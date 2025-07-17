<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class UserDataExport extends Mailable
{
    use Queueable, SerializesModels;

    public $fileName;

    public function __construct($fileName)
    {
        $this->fileName = $fileName;
    }

    public function build()
    {
        // Verify file exists before attaching
        $filePath = storage_path('app/' . $this->fileName);
        if (!file_exists($filePath)) {
            Log::error('Data export file not found', ['file' => $filePath]);
            throw new \Exception('Data export file not found');
        }

        return $this->subject('Your Data Export')
                    ->view('emails.data_export')
                    ->attach($filePath, [
                        'as' => 'user_data.json',
                        'mime' => 'application/json',
                    ]);
    }
}