<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Receipt;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use eXorus\PhpMimeMailParser\Parser;
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
        Log::info('ProcessEmailReceiptJob started', [
            'user_id' => $this->user->id,
            'email' => $this->email,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
            'timeout' => $this->timeout,
        ]);

        try {
            $parser = new Parser();
            $parser->setText($this->rawMessage);
            $subject = $parser->getHeader('subject') ?: 'No Subject';
            $attachments = $parser->getAttachments();

            if (empty($attachments)) {
                Log::warning('No attachments found in email', ['user_id' => $this->user->id, 'email' => $this->email]);
                return;
            }

            foreach ($attachments as $attachment) {
                $content = $attachment->getContent();
                $filename = $attachment->getFilename();

                // Store attachment temporarily
                $path = 'receipts/email/' . $this->user->id . '/' . Carbon::now()->format('Ymd_His') . '_' . $filename;
                Storage::disk('public')->put($path, $content);

                // Create receipt record
                $receipt = Receipt::create([
                    'user_id' => $this->user->id,
                    'source' => 'email',
                    'image_path' => $path,
                    'storage_disk' => 'public',
                    'status' => 'pending',
                    'email_subject' => $subject,
                    'email_received_at' => now(),
                ]);

                Log::info('Receipt created from email attachment', [
                    'receipt_id' => $receipt->id,
                    'user_id' => $this->user->id,
                    'filename' => $filename,
                    'path' => $path,
                ]);

                // Dispatch ProcessReceiptJob to handle the image
                ProcessReceiptJob::dispatch($receipt);
            }

            Log::info('Email receipt processing completed', ['user_id' => $this->user->id, 'email' => $this->email]);
        } catch (\Exception $e) {
            Log::error('Email receipt processing failed', [
                'user_id' => $this->user->id,
                'email' => $this->email,
                'attempt' => $this->attempts(),
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessEmailReceiptJob permanently failed', [
            'user_id' => $this->user->id,
            'email' => $this->email,
            'final_attempt' => $this->attempts(),
            'exception' => $exception->getMessage(),
            'exception_class' => get_class($exception),
        ]);
    }
}