<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessEmailReceiptJob;

class WebhookController extends Controller
{
    public function handleEmailReceipts(Request $request)
    {
        Log::info('Email receipt webhook received', $request->all());

        try {
            $data = $request->validate([
                'email' => 'required|email',
                'rawMessage' => 'required|string',
            ]);

            $senderEmail = $data['email'];
            $rawMessage = $data['rawMessage'];

            // Find user by their email address
            $user = User::where('email', $senderEmail)->first();
            
            if (!$user) {
                Log::warning('User not found for email receipt', [
                    'sender_email' => $senderEmail,
                ]);
                return response()->json(['error' => 'User not found'], 404);
            }

            if (!$user->canReceiveEmailReceipts()) {
                Log::warning('User not eligible for email receipts', [
                    'user_id' => $user->id,
                    'email' => $senderEmail,
                    'user_tier' => $user->user_tier,
                    'email_enabled' => $user->email_receipts_enabled,
                ]);
                return response()->json(['error' => 'User not eligible'], 403);
            }

            // Dispatch job to process the email
            ProcessEmailReceiptJob::dispatch($user, $senderEmail, $rawMessage);

            Log::info('Email receipt job dispatched', [
                'user_id' => $user->id, 
                'email' => $senderEmail
            ]);

            return response()->json(['status' => 'processed']);
            
        } catch (\Exception $e) {
            Log::error('Email receipt webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Processing failed'], 500);
        }
    }
}