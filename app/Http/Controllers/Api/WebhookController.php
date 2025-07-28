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
                'userId' => 'required|integer',
                'rawMessage' => 'required|string',
            ]);

            $email = $data['email'];
            $userId = $data['userId'];
            $rawMessage = $data['rawMessage'];

            // Find the user
            $user = User::find($userId);
            if (!$user || $user->user_tier !== 'pro' || !$user->email_receipts_enabled) {
                Log::warning('User not eligible for email receipts', [
                    'user_id' => $userId,
                    'user_exists' => !!$user,
                    'user_tier' => $user->user_tier ?? 'none',
                    'email_enabled' => $user->email_receipts_enabled ?? false,
                ]);
                return response()->json(['error' => 'User not eligible'], 403);
            }

            // Dispatch job to process the email
            ProcessEmailReceiptJob::dispatch($user, $email, $rawMessage);

            Log::info('Email receipt job dispatched', ['user_id' => $userId, 'email' => $email]);

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