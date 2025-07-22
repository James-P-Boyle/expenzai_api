<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function updateEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:255|unique:users,email,' . $request->user()->id,
        ]);

        $user = $request->user();
        $oldEmail = $user->email;
        $user->email = $request->email;
        $user->save();

        // Log the email change (for GDPR audit)
        Log::info('User email updated', [
            'user_id' => $user->id,
            'old_email' => $oldEmail,
            'new_email' => $request->email,
            'timestamp' => now(),
        ]);

        return response()->json(['message' => 'Email updated successfully']);
    }

    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        // Revoke all tokens
        $user->tokens()->delete();

        // Soft delete or hard delete the user
        $user->delete(); // Use softDeletes() in User model for soft deletion

        // Log the deletion (for GDPR audit)
        Log::info('User account deleted', [
            'user_id' => $user->id,
            'email' => $user->email,
            'timestamp' => now(),
        ]);

        return response()->json(['message' => 'Account deleted successfully']);
    }

    public function requestData(Request $request)
    {
        try {
            $user = $request->user();
            $data = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ];

            // Generate a JSON file
            $fileName = 'user_data_' . $user->id . '_' . time() . '.json';
            $fileContent = json_encode($data, JSON_PRETTY_PRINT);
            Log::info('Attempting to store file', ['file' => $fileName, 'path' => storage_path('app/')]);

            $stored = Storage::disk('local')->put($fileName, $fileContent);

            if (!$stored) {
                Log::error('Failed to store data export file', ['file' => $fileName]);
                throw new \Exception('Failed to store data export file');
            }

            $filePath = storage_path('app/' . $fileName);
            Log::info('Checking file existence', ['filePath' => $filePath]);

            if (!file_exists($filePath)) {
                Log::error('Data export file not found after storage', ['file' => $fileName]);
                throw new \Exception('Data export file not found');
            }

            // Send email with attachment
            Mail::to($user->email)->send(new \App\Mail\UserDataExport($fileName));

            // Log the data request (for GDPR audit)
            Log::info('User data export requested', [
                'user_id' => $user->id,
                'email' => $user->email,
                'timestamp' => now(),
            ]);

            return response()->json(['message' => 'Data export requested successfully']);
        } catch (\Exception $e) {
            Log::error('Data export request failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Failed to process data export request'], 500);
        }
    }
}