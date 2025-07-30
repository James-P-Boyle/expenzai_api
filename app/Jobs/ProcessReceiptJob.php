<?php

namespace App\Jobs;

use App\Models\Receipt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Carbon\Carbon;

class ProcessReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; 
    public $tries = 3; 

    public function __construct(
        public Receipt $receipt,
        public bool $skipDateExtraction = false
    ) {}

    public function handle(): void
    {
        Log::info('ProcessReceiptJob started', [
            'receipt_id' => $this->receipt->id,
            'skip_date_extraction' => $this->skipDateExtraction,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
            'timeout' => $this->timeout,
            'receipt_data' => [
                'image_path' => $this->receipt->image_path,
                'storage_disk' => $this->receipt->storage_disk,
                'status' => $this->receipt->status,
                'receipt_date' => $this->receipt->receipt_date,
            ],
            'config_check' => [
                'openai_key_set' => !empty(config('services.openai.api_key')),
                'openai_key_length' => strlen(config('services.openai.api_key') ?? ''),
            ]
        ]);

        if (empty(config('services.openai.api_key'))) {
            Log::error('OpenAI API key not configured', ['receipt_id' => $this->receipt->id]);
            $this->receipt->update(['status' => 'failed']);
            return;
        }

        try {
            // Get optimized base64 encoded image
            Log::info('Starting image optimization', ['receipt_id' => $this->receipt->id]);
            $base64Image = $this->getOptimizedImage();
            Log::info('Image optimization completed successfully', [
                'receipt_id' => $this->receipt->id,
                'base64_length' => strlen($base64Image)
            ]);

            Log::info('About to call OpenAI API', [
                'receipt_id' => $this->receipt->id,
                'image_size_bytes' => strlen($base64Image),
                'model' => 'gpt-4o',
                'skip_date_extraction' => $this->skipDateExtraction
            ]);

            try {
                $response = Http::timeout(180)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . config('services.openai.api_key'),
                        'Content-Type' => 'application/json',
                    ])->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o',
                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => [
                                    [
                                        'type' => 'text',
                                        'text' => $this->getPrompt()
                                    ],
                                    [
                                        'type' => 'image_url',
                                        'image_url' => [
                                            'url' => "data:image/jpeg;base64,{$base64Image}"
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'max_tokens' => 2000,
                        'temperature' => 0.1
                    ]);

                Log::info('OpenAI API call completed', [
                    'receipt_id' => $this->receipt->id,
                    'status_code' => $response->status(),
                    'successful' => $response->successful(),
                    'response_size' => strlen($response->body()),
                    'has_choices' => isset($response->json()['choices'])
                ]);

            } catch (\Exception $e) {
                Log::error('OpenAI API call failed with exception', [
                    'receipt_id' => $this->receipt->id,
                    'error_message' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }

            if ($response->successful()) {
                Log::info('Processing successful OpenAI response', ['receipt_id' => $this->receipt->id]);
                
                $aiResponse = $response->json();
                
                if (!isset($aiResponse['choices'][0]['message']['content'])) {
                    Log::error('Invalid OpenAI response structure', [
                        'receipt_id' => $this->receipt->id,
                        'response_keys' => array_keys($aiResponse),
                        'has_choices' => isset($aiResponse['choices']),
                        'choices_count' => isset($aiResponse['choices']) ? count($aiResponse['choices']) : 0
                    ]);
                    throw new \Exception('Invalid OpenAI response structure');
                }
                
                $content = $aiResponse['choices'][0]['message']['content'];
                
                Log::info('Parsing AI response content', [
                    'receipt_id' => $this->receipt->id,
                    'content_length' => strlen($content),
                    'content_preview' => substr($content, 0, 200)
                ]);
                
                $this->parseAndStoreReceipt($content);
                
                $this->receipt->update(['status' => 'completed']);
                
                Log::info('Receipt processing completed successfully', [
                    'receipt_id' => $this->receipt->id,
                    'final_status' => 'completed'
                ]);
                
            } else {
                Log::error('OpenAI API Error - Non-successful response', [
                    'receipt_id' => $this->receipt->id,
                    'status' => $response->status(),
                    'response_body' => $response->body(),
                    'headers' => $response->headers()
                ]);
                $this->receipt->update(['status' => 'failed']);
                throw new \Exception('OpenAI API returned non-successful status: ' . $response->status());
            }
        
        } catch (\Exception $e) {
            Log::error('Receipt Processing Error - Main catch block', [
                'receipt_id' => $this->receipt->id,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->receipt->update(['status' => 'failed']);
            
            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    private function getOptimizedImage(): string
    {
        $imagePath = $this->receipt->image_path;
        $disk = $this->receipt->storage_disk ?? 'public';
        
        Log::info('Getting optimized image', [
            'receipt_id' => $this->receipt->id,
            'image_path' => $imagePath,
            'disk' => $disk,
            'disk_config_exists' => config("filesystems.disks.{$disk}") !== null
        ]);
        
        try {
            $originalImageContent = Storage::disk($disk)->get($imagePath);
        } catch (\Exception $e) {
            Log::error('Failed to read image from storage', [
                'receipt_id' => $this->receipt->id,
                'disk' => $disk,
                'path' => $imagePath,
                'error' => $e->getMessage(),
                'error_class' => get_class($e)
            ]);
            throw $e;
        }

        $originalSize = strlen($originalImageContent);

        Log::info('Original image stats', [
            'receipt_id' => $this->receipt->id,
            'original_size_kb' => round($originalSize / 1024, 2),
            'path' => $imagePath,
            'disk' => $disk
        ]);

        // If image is already small enough (under 500KB), return as-is
        if ($originalSize <= 512000) { // 500KB
            Log::info('Image is small enough, using original', [
                'receipt_id' => $this->receipt->id,
                'size_kb' => round($originalSize / 1024, 2)
            ]);
            return base64_encode($originalImageContent);
        }

        try {
            Log::info('Starting image optimization process', ['receipt_id' => $this->receipt->id]);
            
            // Create image manager with GD driver
            $manager = new ImageManager(new Driver());
            $image = $manager->read($originalImageContent);

            // Get original dimensions
            $originalWidth = $image->width();
            $originalHeight = $image->height();

            Log::info('Original image dimensions', [
                'receipt_id' => $this->receipt->id,
                'width' => $originalWidth,
                'height' => $originalHeight
            ]);

            // Calculate new dimensions (max 1600px on longest side)
            $maxDimension = 1600;
            if ($originalWidth > $originalHeight) {
                $newWidth = min($originalWidth, $maxDimension);
                $newHeight = ($originalHeight * $newWidth) / $originalWidth;
            } else {
                $newHeight = min($originalHeight, $maxDimension);
                $newWidth = ($originalWidth * $newHeight) / $originalHeight;
            }

            // Resize image
            $image->resize($newWidth, $newHeight);

            // Convert to JPEG with optimized quality
            $optimizedContent = $image->toJpeg(quality: 80);

            $optimizedSize = strlen($optimizedContent);

            Log::info('Image optimization completed', [
                'receipt_id' => $this->receipt->id,
                'original_size_kb' => round($originalSize / 1024, 2),
                'optimized_size_kb' => round($optimizedSize / 1024, 2),
                'compression_ratio' => round(($originalSize - $optimizedSize) / $originalSize * 100, 1) . '%',
                'dimensions' => "{$newWidth}x{$newHeight}"
            ]);

            return base64_encode($optimizedContent);

        } catch (\Exception $e) {
            Log::warning('Image optimization failed, using original', [
                'receipt_id' => $this->receipt->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e)
            ]);
            
            // Fallback to original image if optimization fails
            return base64_encode($originalImageContent);
        }
    }

    public function retryUntil()
    {
        return now()->addMinutes(15); 
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessReceiptJob permanently failed', [
            'receipt_id' => $this->receipt->id,
            'final_attempt' => $this->attempts(),
            'exception' => $exception->getMessage(),
            'exception_class' => get_class($exception)
        ]);
        
        $this->receipt->update(['status' => 'failed']);
    }

    private function getPrompt(): string
    {
        if ($this->skipDateExtraction) {
            return "
            Analyze this receipt image and extract the following information in JSON format:

            {
                \"store_name\": \"Store name from receipt\",
                \"total_amount\": 0.00,
                \"items\": [
                    {
                        \"name\": \"Item name\",
                        \"price\": 0.00,
                        \"category\": \"Food & Groceries|Household|Personal Care|Beverages|Snacks|Meat & Deli|Dairy|Vegetables|Fruits|Other\",
                        \"is_uncertain\": false
                    }
                ]
            }

            Rules:
            1. Extract ALL items with their individual prices
            2. Categorize each item into one of the predefined categories
            3. Set 'is_uncertain' to true if you're not confident about the category
            4. Use decimal format for prices (e.g., 3.79, not 379)
            5. If you can't read an item name clearly, still include it but mark as uncertain
            6. Skip any deposit (PFAND) entries - these are not actual purchases
            7. DO NOT extract the receipt date - it will be provided separately
            8. Return ONLY the JSON, no additional text

            Be very careful with price extraction and make sure the total matches the sum of individual items.
            ";
        }

        // Original prompt with date extraction
        return "
        Analyze this receipt image and extract the following information in JSON format:

        {
            \"store_name\": \"Store name from receipt\",
            \"receipt_date\": \"YYYY-MM-DD format\",
            \"total_amount\": 0.00,
            \"items\": [
                {
                    \"name\": \"Item name\",
                    \"price\": 0.00,
                    \"category\": \"Food & Groceries|Household|Personal Care|Beverages|Snacks|Meat & Deli|Dairy|Vegetables|Fruits|Other\",
                    \"is_uncertain\": false
                }
            ]
        }

        Rules:
        1. Extract ALL items with their individual prices
        2. Categorize each item into one of the predefined categories
        3. Set 'is_uncertain' to true if you're not confident about the category
        4. Use decimal format for prices (e.g., 3.79, not 379)
        5. If you can't read an item name clearly, still include it but mark as uncertain
        6. Skip any deposit (PFAND) entries - these are not actual purchases
        7. Return ONLY the JSON, no additional text

        Be very careful with price extraction and make sure the total matches the sum of individual items.
        ";
    }

    private function parseAndStoreReceipt(string $aiResponse): void
    {
        try {
            Log::info('Starting to parse AI response', [
                'receipt_id' => $this->receipt->id,
                'skip_date_extraction' => $this->skipDateExtraction,
                'response_length' => strlen($aiResponse)
            ]);
            
            // Clean the response 
            $jsonStart = strpos($aiResponse, '{');
            $jsonEnd = strrpos($aiResponse, '}') + 1;
            
            if ($jsonStart === false || $jsonEnd === false) {
                Log::error('Could not find JSON boundaries in AI response', [
                    'receipt_id' => $this->receipt->id,
                    'response' => $aiResponse
                ]);
                throw new \Exception('Invalid AI response - no JSON found');
            }
            
            $jsonString = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart);
            
            Log::info('Extracted JSON string', [
                'receipt_id' => $this->receipt->id,
                'json_length' => strlen($jsonString),
                'json_preview' => substr($jsonString, 0, 200)
            ]);
            
            $data = json_decode($jsonString, true);
            
            if (!$data) {
                Log::error('JSON decode failed', [
                    'json_error' => json_last_error_msg(),
                    'json_error_code' => json_last_error(),
                    'receipt_id' => $this->receipt->id,
                    'json_string' => $jsonString
                ]);
                throw new \Exception('Invalid JSON response from AI: ' . json_last_error_msg());
            }
            
            Log::info('JSON parsed successfully', [
                'receipt_id' => $this->receipt->id,
                'data_keys' => array_keys($data),
                'items_count' => isset($data['items']) ? count($data['items']) : 0
            ]);
            
            // Prepare update data
            $updateData = [
                'store_name' => $data['store_name'] ?? null,
                'total_amount' => $data['total_amount'] ?? null,
            ];

            // Only update date if we're not skipping extraction and date was extracted
            if (!$this->skipDateExtraction && isset($data['receipt_date'])) {
                try {
                    $extractedDate = Carbon::parse($data['receipt_date']);
                    $updateData['receipt_date'] = $extractedDate;
                    $updateData['week_of'] = $extractedDate->copy()->startOfWeek();
                    
                    Log::info('Date extracted from AI', [
                        'receipt_id' => $this->receipt->id,
                        'extracted_date' => $extractedDate->toDateString()
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to parse extracted date, keeping existing', [
                        'receipt_id' => $this->receipt->id,
                        'extracted_date' => $data['receipt_date'] ?? 'null',
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                Log::info('Date extraction skipped or no date in response', [
                    'receipt_id' => $this->receipt->id,
                    'skip_date_extraction' => $this->skipDateExtraction,
                    'has_date_in_response' => isset($data['receipt_date']),
                    'current_receipt_date' => $this->receipt->receipt_date
                ]);
            }

            $this->receipt->update($updateData);
    
            Log::info('Receipt data updated', [
                'receipt_id' => $this->receipt->id,
                'store_name' => $data['store_name'] ?? null,
                'total_amount' => $data['total_amount'] ?? null,
                'date_skipped' => $this->skipDateExtraction,
                'final_receipt_date' => $this->receipt->fresh()->receipt_date
            ]);
    
            // Store items
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $index => $itemData) {
                    try {
                        $item = $this->receipt->items()->create([
                            'name' => $itemData['name'] ?? 'Unknown Item',
                            'price' => $itemData['price'] ?? 0,
                            'category' => $itemData['category'] ?? 'Other',
                            'is_uncertain' => $itemData['is_uncertain'] ?? false,
                        ]);
                        
                        Log::info('Item created', [
                            'receipt_id' => $this->receipt->id,
                            'item_id' => $item->id,
                            'item_index' => $index,
                            'item_name' => $item->name,
                            'item_price' => $item->price
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to create item', [
                            'receipt_id' => $this->receipt->id,
                            'item_index' => $index,
                            'item_data' => $itemData,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                Log::info('All items processed', [
                    'receipt_id' => $this->receipt->id,
                    'total_items' => count($data['items'])
                ]);
            }
    
        } catch (\Exception $e) {
            Log::error('Receipt parsing error', [
                'receipt_id' => $this->receipt->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}