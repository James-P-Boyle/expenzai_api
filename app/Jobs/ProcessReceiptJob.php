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

    public function __construct(
        public Receipt $receipt
    ) {}

    public function handle(): void
    {
        Log::info('ProcessReceiptJob started', [
            'receipt_id' => $this->receipt->id,
            'attempt' => $this->attempts()
        ]);

        try {
            // Get optimized base64 encoded image
            $base64Image = $this->getOptimizedImage();

            Log::info('About to call OpenAI API', ['receipt_id' => $this->receipt->id]);

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

            if ($response->successful()) {
                $aiResponse = $response->json();
                $content = $aiResponse['choices'][0]['message']['content'];
                
                $this->parseAndStoreReceipt($content);
                
                $this->receipt->update(['status' => 'completed']);
                
                Log::info('Receipt processing completed successfully', [
                    'receipt_id' => $this->receipt->id
                ]);
                
            } else {
                Log::error('OpenAI API Error', [
                    'receipt_id' => $this->receipt->id,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                $this->receipt->update(['status' => 'failed']);
            }

            Log::info('OpenAI API response received', [
                'receipt_id' => $this->receipt->id,
                'status_code' => $response->status(),
                'successful' => $response->successful()
            ]);
        
        } catch (\Exception $e) {
            Log::error('Receipt Processing Error', [
                'receipt_id' => $this->receipt->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->receipt->update(['status' => 'failed']);
        }
    }

    private function getOptimizedImage(): string
    {
        $imagePath = $this->receipt->image_path;
        
        // Check which storage disk to use
        $disk = $this->receipt->storage_disk ?? 'public';
        
        try {
            $originalImageContent = Storage::disk($disk)->get($imagePath);
        } catch (\Exception $e) {
            Log::error('Failed to read image from storage', [
                'receipt_id' => $this->receipt->id,
                'disk' => $disk,
                'path' => $imagePath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }

        $originalSize = strlen($originalImageContent);

        Log::info('Original image stats', [
            'receipt_id' => $this->receipt->id,
            'original_size_kb' => $originalSize / 1024,
            'path' => $imagePath,
            'disk' => $disk
        ]);

        // If image is already small enough (under 500KB), return as-is
        if ($originalSize <= 512000) { // 500KB
            return base64_encode($originalImageContent);
        }

        try {
            // Create image manager with GD driver
            $manager = new ImageManager(new Driver());
            $image = $manager->read($originalImageContent);

            // Get original dimensions
            $originalWidth = $image->width();
            $originalHeight = $image->height();

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
                'original_size_kb' => $originalSize / 1024,
                'optimized_size_kb' => $optimizedSize / 1024,
                'compression_ratio' => round(($originalSize - $optimizedSize) / $originalSize * 100, 1) . '%',
                'dimensions' => "{$newWidth}x{$newHeight}"
            ]);

            return base64_encode($optimizedContent);

        } catch (\Exception $e) {
            Log::warning('Image optimization failed, using original', [
                'receipt_id' => $this->receipt->id,
                'error' => $e->getMessage()
            ]);
            
            // Fallback to original image if optimization fails
            return base64_encode($originalImageContent);
        }
    }

    public function retryUntil()
    {
        return now()->addMinutes(10);
    }

    private function getPrompt(): string
    {
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
            Log::info('Starting to parse AI response', ['receipt_id' => $this->receipt->id]);
            
            // Clean the response - sometimes AI includes markdown formatting
            $jsonStart = strpos($aiResponse, '{');
            $jsonEnd = strrpos($aiResponse, '}') + 1;
            $jsonString = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart);
            
            $data = json_decode($jsonString, true);
            
            if (!$data) {
                Log::error('JSON decode failed', [
                    'json_error' => json_last_error_msg(),
                    'receipt_id' => $this->receipt->id
                ]);
                throw new \Exception('Invalid JSON response from AI: ' . json_last_error_msg());
            }
            
            // Update receipt with extracted data
            $this->receipt->update([
                'store_name' => $data['store_name'] ?? null,
                'receipt_date' => isset($data['receipt_date']) ? Carbon::parse($data['receipt_date']) : null,
                'total_amount' => $data['total_amount'] ?? null,
                'week_of' => isset($data['receipt_date']) 
                    ? Carbon::parse($data['receipt_date'])->startOfWeek() 
                    : Carbon::now()->startOfWeek(),
            ]);
    
            // Store items
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $this->receipt->items()->create([
                        'name' => $itemData['name'] ?? 'Unknown Item',
                        'price' => $itemData['price'] ?? 0,
                        'category' => $itemData['category'] ?? 'Other',
                        'is_uncertain' => $itemData['is_uncertain'] ?? false,
                    ]);
                }
            }
    
        } catch (\Exception $e) {
            Log::error('Receipt parsing error', [
                'receipt_id' => $this->receipt->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}