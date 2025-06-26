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
use Carbon\Carbon;

class ProcessReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Receipt $receipt
    ) {}

    public function handle(): void
    {
        try {
            // Get base64 encoded image
            $imageContent = Storage::disk('public')->get($this->receipt->image_path);
            $base64Image = base64_encode($imageContent);

            // Call OpenAI Vision API
            $response = Http::withHeaders([
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
                
                // Parse AI response
                $this->parseAndStoreReceipt($content);
                
                $this->receipt->update(['status' => 'completed']);
                
            } else {
                Log::error('OpenAI API Error', [
                    'receipt_id' => $this->receipt->id,
                    'response' => $response->body()
                ]);
                $this->receipt->update(['status' => 'failed']);
            }

        } catch (\Exception $e) {
            Log::error('Receipt Processing Error', [
                'receipt_id' => $this->receipt->id,
                'error' => $e->getMessage()
            ]);
            
            $this->receipt->update(['status' => 'failed']);
        };
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
            
            // Log the raw AI response first
            Log::info('Raw AI Response', ['ai_response' => $aiResponse]);
            
            // Clean the response - sometimes AI includes markdown formatting
            $jsonStart = strpos($aiResponse, '{');
            $jsonEnd = strrpos($aiResponse, '}') + 1;
            $jsonString = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart);
            
            Log::info('Extracted JSON string', ['json_string' => $jsonString]);
            
            $data = json_decode($jsonString, true);
            
            if (!$data) {
                Log::error('JSON decode failed', [
                    'json_error' => json_last_error_msg(),
                    'json_string' => $jsonString
                ]);
                throw new \Exception('Invalid JSON response from AI: ' . json_last_error_msg());
            }
            
            Log::info('Successfully decoded JSON', ['decoded_data' => $data]);
            
            // Your logger call
            logger([
                'store_name' => $data['store_name'] ?? null,
                'receipt_date' => isset($data['receipt_date']) ? Carbon::parse($data['receipt_date']) : null,
                'total_amount' => $data['total_amount'] ?? null,
                'week_of' => isset($data['receipt_date']) 
                    ? Carbon::parse($data['receipt_date'])->startOfWeek() 
                    : Carbon::now()->startOfWeek(),
            ]);
            
            Log::info('About to update receipt');
            
            // Update receipt with extracted data
            $this->receipt->update([
                'store_name' => $data['store_name'] ?? null,
                'receipt_date' => isset($data['receipt_date']) ? Carbon::parse($data['receipt_date']) : null,
                'total_amount' => $data['total_amount'] ?? null,
                'week_of' => isset($data['receipt_date']) 
                    ? Carbon::parse($data['receipt_date'])->startOfWeek() 
                    : Carbon::now()->startOfWeek(),
            ]);
    
            Log::info('Receipt updated successfully');
    
            // Store items
            if (isset($data['items']) && is_array($data['items'])) {
                Log::info('Processing items', ['item_count' => count($data['items'])]);
                
                foreach ($data['items'] as $index => $itemData) {
                    Log::info("Processing item {$index}", ['item_data' => $itemData]);
                    
                    $this->receipt->items()->create([
                        'name' => $itemData['name'] ?? 'Unknown Item',
                        'price' => $itemData['price'] ?? 0,
                        'category' => $itemData['category'] ?? 'Other',
                        'is_uncertain' => $itemData['is_uncertain'] ?? false,
                    ]);
                }
                
                Log::info('All items processed successfully');
            }
    
        } catch (\Exception $e) {
            Log::error('Receipt parsing error', [
                'receipt_id' => $this->receipt->id,
                'ai_response' => $aiResponse,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
