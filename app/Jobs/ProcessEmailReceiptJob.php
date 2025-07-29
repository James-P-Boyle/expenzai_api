<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Receipt;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessEmailReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;

    protected $user;
    protected $email;
    protected $rawMessage;

    public function __construct(User $user, string $email, string $rawMessage)
    {
        $this->user = $user;
        $this->email = $email;
        $this->rawMessage = $rawMessage;
    }

    public function handle()
    {
        Log::info('🚀 ProcessEmailReceiptJob started (Alternative Parser)', [
            'user_id' => $this->user->id,
            'user_email' => $this->user->email,
            'sender_email' => $this->email,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
            'raw_message_length' => strlen($this->rawMessage),
        ]);

        try {
            // Simple email parsing without mailparse extension
            $subject = $this->extractHeader('Subject') ?: 'No Subject';
            $from = $this->extractHeader('From') ?: 'Unknown Sender';
            
            Log::info('📧 Email headers parsed (simple)', [
                'subject' => $subject,
                'from' => $from,
            ]);

            // Look for base64 encoded attachments or multipart content
            $attachments = $this->extractAttachments();
            
            Log::info('📎 Attachments extracted', [
                'count' => count($attachments),
                'attachment_info' => array_map(function($attachment) {
                    return [
                        'filename' => $attachment['filename'],
                        'content_type' => $attachment['content_type'],
                        'size' => strlen($attachment['content']),
                    ];
                }, $attachments)
            ]);

            if (empty($attachments)) {
                Log::warning('❌ No attachments found in email', [
                    'user_id' => $this->user->id,
                    'email' => $this->email,
                    'subject' => $subject,
                ]);
                return;
            }

            $processedCount = 0;
            $validImageTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];

            foreach ($attachments as $index => $attachment) {
                Log::info("📎 Processing attachment {$index}", [
                    'filename' => $attachment['filename'],
                    'content_type' => $attachment['content_type'],
                ]);

                $content = $attachment['content'];
                $filename = $attachment['filename'];
                $contentType = $attachment['content_type'];

                // Validate it's an image
                if (!in_array(strtolower($contentType), $validImageTypes)) {
                    Log::info("⏭️ Skipping non-image attachment", [
                        'filename' => $filename,
                        'content_type' => $contentType,
                    ]);
                    continue;
                }

                // Store attachment
                $path = 'receipts/email/' . $this->user->id . '/' . Carbon::now()->format('Ymd_His') . '_' . $filename;
                
                try {
                    $stored = Storage::disk('public')->put($path, $content);
                    Log::info("💾 File stored successfully", [
                        'path' => $path,
                        'stored' => $stored,
                    ]);
                } catch (\Exception $e) {
                    Log::error("💾 Failed to store file", [
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                // Create receipt record
                try {
                    $receipt = Receipt::create([
                        'user_id' => $this->user->id,
                        'source' => 'email',
                        'image_path' => $path,
                        'storage_disk' => 'public',
                        'status' => 'pending',
                        'email_subject' => $subject,
                        'email_received_at' => now(),
                    ]);

                    Log::info('🧾 Receipt created successfully', [
                        'receipt_id' => $receipt->id,
                        'user_id' => $this->user->id,
                        'filename' => $filename,
                        'path' => $path,
                    ]);

                    // Dispatch ProcessReceiptJob
                    try {
                        \App\Jobs\ProcessReceiptJob::dispatch($receipt);
                        Log::info("🔄 ProcessReceiptJob dispatched successfully", [
                            'receipt_id' => $receipt->id,
                        ]);
                    } catch (\Exception $e) {
                        Log::error("🔄 Failed to dispatch ProcessReceiptJob", [
                            'receipt_id' => $receipt->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $processedCount++;

                } catch (\Exception $e) {
                    Log::error("🧾 Failed to create receipt record", [
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            Log::info('✅ Email receipt processing completed', [
                'user_id' => $this->user->id,
                'email' => $this->email,
                'processed_count' => $processedCount,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Email receipt processing failed', [
                'user_id' => $this->user->id,
                'email' => $this->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function extractHeader($headerName)
    {
        $pattern = "/^{$headerName}:\s*(.+)$/mi";
        if (preg_match($pattern, $this->rawMessage, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    private function extractAttachments()
    {
        $attachments = [];
        
        // Look for multipart boundaries
        if (preg_match('/boundary[=: ]+(["\']?)([^"\'\s;]+)\1/i', $this->rawMessage, $matches)) {
            $boundary = $matches[2];
            $parts = explode("--{$boundary}", $this->rawMessage);
            
            foreach ($parts as $part) {
                if (strpos($part, 'Content-Disposition: attachment') !== false) {
                    $attachment = $this->parseAttachmentPart($part);
                    if ($attachment) {
                        $attachments[] = $attachment;
                    }
                }
            }
        }
        
        return $attachments;
    }
    
    private function parseAttachmentPart($part)
    {
        // Extract filename
        if (preg_match('/filename[=: ]+(["\']?)([^"\'\r\n;]+)\1/i', $part, $matches)) {
            $filename = $matches[2];
        } else {
            $filename = 'attachment_' . time();
        }
        
        // Extract content type
        if (preg_match('/Content-Type:\s*([^\r\n;]+)/i', $part, $matches)) {
            $contentType = trim($matches[1]);
        } else {
            $contentType = 'application/octet-stream';
        }
        
        // Extract content (very basic - just get everything after double newline)
        $parts = preg_split('/\r?\n\r?\n/', $part, 2);
        if (count($parts) > 1) {
            $content = trim($parts[1]);
            
            // If base64 encoded, decode it
            if (strpos($part, 'Content-Transfer-Encoding: base64') !== false) {
                $content = base64_decode($content);
            }
            
            return [
                'filename' => $filename,
                'content_type' => $contentType,
                'content' => $content,
            ];
        }
        
        return null;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('💀 ProcessEmailReceiptJob permanently failed', [
            'user_id' => $this->user->id,
            'email' => $this->email,
            'exception' => $exception->getMessage(),
        ]);
    }
}