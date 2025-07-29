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
        Log::info('🚀 ProcessEmailReceiptJob started (Enhanced Parser)', [
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
            
            Log::info('📧 Email headers parsed', [
                'subject' => $subject,
                'from' => $from,
            ]);

            // Enhanced attachment extraction
            $attachments = $this->extractAttachments();
            
            Log::info('📎 Attachments extracted', [
                'count' => count($attachments),
                'attachment_info' => array_map(function($attachment) {
                    return [
                        'filename' => $attachment['filename'],
                        'content_type' => $attachment['content_type'],
                        'size' => strlen($attachment['content']),
                        'encoding' => $attachment['encoding'] ?? 'none',
                    ];
                }, $attachments)
            ]);

            if (empty($attachments)) {
                Log::warning('❌ No attachments found in email', [
                    'user_id' => $this->user->id,
                    'email' => $this->email,
                    'subject' => $subject,
                    'has_boundary' => $this->hasBoundary(),
                    'content_type' => $this->extractHeader('Content-Type'),
                ]);
                return;
            }

            $processedCount = 0;
            $validImageTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];

            foreach ($attachments as $index => $attachment) {
                Log::info("📎 Processing attachment {$index}", [
                    'filename' => $attachment['filename'],
                    'content_type' => $attachment['content_type'],
                    'size' => strlen($attachment['content']),
                ]);

                $content = $attachment['content'];
                $filename = $attachment['filename'];
                $contentType = strtolower($attachment['content_type']);

                // Validate it's an image
                if (!in_array($contentType, $validImageTypes)) {
                    Log::info("⏭️ Skipping non-image attachment", [
                        'filename' => $filename,
                        'content_type' => $contentType,
                    ]);
                    continue;
                }

                // Validate content is not empty
                if (empty($content)) {
                    Log::warning("⚠️ Skipping empty attachment", [
                        'filename' => $filename,
                    ]);
                    continue;
                }

                // Store attachment
                $timestamp = Carbon::now()->format('Ymd_His');
                $sanitizedFilename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
                $path = "receipts/email/{$this->user->id}/{$timestamp}_{$sanitizedFilename}";
                
                try {
                    $stored = Storage::disk('public')->put($path, $content);
                    Log::info("💾 File stored successfully", [
                        'path' => $path,
                        'stored' => $stored,
                        'size' => strlen($content),
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
                        'trace' => $e->getTraceAsString(),
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

    private function hasBoundary()
    {
        return preg_match('/boundary[=: ]+(["\']?)([^"\'\s;]+)\1/i', $this->rawMessage) ? true : false;
    }

    private function extractAttachments()
    {
        $attachments = [];
        
        // Look for multipart boundaries
        if (preg_match('/boundary[=: ]+(["\']?)([^"\'\s;]+)\1/i', $this->rawMessage, $matches)) {
            $boundary = $matches[2];
            Log::info('📬 Found boundary', ['boundary' => $boundary]);
            
            $parts = preg_split("/--" . preg_quote($boundary, '/') . "/", $this->rawMessage);
            
            Log::info('📦 Split into parts', ['part_count' => count($parts)]);
            
            foreach ($parts as $index => $part) {
                if (empty(trim($part)) || strpos($part, '--') === 0) {
                    continue; // Skip empty parts or end boundary
                }
                
                Log::info("🔍 Analyzing part {$index}", [
                    'length' => strlen($part),
                    'has_content_type' => strpos($part, 'Content-Type:') !== false,
                    'has_content_disposition' => strpos($part, 'Content-Disposition:') !== false,
                ]);
                
                // Look for any part that might be an image (not just attachments)
                if ($this->isImagePart($part)) {
                    $attachment = $this->parseAttachmentPart($part);
                    if ($attachment) {
                        $attachments[] = $attachment;
                        Log::info("✅ Found image attachment", [
                            'filename' => $attachment['filename'],
                            'content_type' => $attachment['content_type'],
                        ]);
                    }
                }
            }
        } else {
            Log::info('📬 No boundary found, checking for single part message');
            
            // Handle single-part messages or simple base64 encoded images
            if ($this->isImagePart($this->rawMessage)) {
                $attachment = $this->parseAttachmentPart($this->rawMessage);
                if ($attachment) {
                    $attachments[] = $attachment;
                }
            }
        }
        
        return $attachments;
    }
    
    private function isImagePart($part)
    {
        // Check if this part contains image content
        $imagePatterns = [
            '/Content-Type:\s*image\//i',
            '/Content-Disposition:.*attachment/i',
            '/Content-Disposition:.*inline/i',
            '/filename.*\.(jpe?g|png|gif|webp)/i',
        ];
        
        foreach ($imagePatterns as $pattern) {
            if (preg_match($pattern, $part)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function parseAttachmentPart($part)
    {
        // Extract filename - try multiple patterns
        $filename = 'receipt_' . time() . '.jpg'; // default
        
        $filenamePatterns = [
            '/filename[=: ]*["\']?([^"\'\r\n;]+)["\']?/i',
            '/name[=: ]*["\']?([^"\'\r\n;]+)["\']?/i',
        ];
        
        foreach ($filenamePatterns as $pattern) {
            if (preg_match($pattern, $part, $matches)) {
                $filename = trim($matches[1], '"\'');
                break;
            }
        }
        
        // Extract content type
        $contentType = 'image/jpeg'; // default
        if (preg_match('/Content-Type:\s*([^\r\n;]+)/i', $part, $matches)) {
            $contentType = trim($matches[1]);
        }
        
        // Extract encoding
        $encoding = null;
        if (preg_match('/Content-Transfer-Encoding:\s*([^\r\n]+)/i', $part, $matches)) {
            $encoding = trim($matches[1]);
        }
        
        // Extract content - look for double newline separator
        $contentStart = strpos($part, "\r\n\r\n");
        if ($contentStart === false) {
            $contentStart = strpos($part, "\n\n");
        }
        
        if ($contentStart === false) {
            Log::warning('🔍 Could not find content separator in part');
            return null;
        }
        
        $content = substr($part, $contentStart + (strpos($part, "\r\n\r\n") !== false ? 4 : 2));
        $content = trim($content);
        
        // Remove any trailing boundary markers
        $content = preg_replace('/--[a-zA-Z0-9_-]+--?\s*$/', '', $content);
        $content = trim($content);
        
        // Decode based on encoding
        if ($encoding && strtolower($encoding) === 'base64') {
            $decodedContent = base64_decode($content);
            if ($decodedContent === false) {
                Log::warning('🔍 Failed to decode base64 content');
                return null;
            }
            $content = $decodedContent;
        } elseif ($encoding && strtolower($encoding) === 'quoted-printable') {
            $content = quoted_printable_decode($content);
        }
        
        // Validate we have actual content
        if (empty($content) || strlen($content) < 100) {
            Log::warning('🔍 Content too small or empty', [
                'content_length' => strlen($content),
                'encoding' => $encoding,
            ]);
            return null;
        }
        
        return [
            'filename' => $filename,
            'content_type' => $contentType,
            'content' => $content,
            'encoding' => $encoding,
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('💀 ProcessEmailReceiptJob permanently failed', [
            'user_id' => $this->user->id,
            'email' => $this->email,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}