<?php

namespace App\Http\Controllers\Api;

use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Jobs\ProcessReceiptJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ReceiptController extends Controller
{
    public function index(Request $request)
    {
        $receipts = $request->user()
            ->receipts()
            ->with(['items'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);
        Log::info($receipts);
        return response()->json($receipts);
    }

    public function getAnonymousReceipts($sessionId)
    {
        $receipts = Receipt::where('session_id', $sessionId)
                        ->whereNull('user_id')
                        ->with('items')
                        ->orderBy('created_at', 'desc')
                        ->get();

        return response()->json([
            'data' => $receipts,
            'session_id' => $sessionId,
            'total_count' => $receipts->count(),
            'remaining_uploads' => max(0, 3 - $receipts->count()),
        ]);
    }

    public function store(Request $request)
    {
        try {
            // Check if file was uploaded
            if (!$request->hasFile('image')) {
                // Check if this is a PHP upload limit issue
                if ($request->has('image')) {
                    $uploadMax = ini_get('upload_max_filesize');
                    return response()->json([
                        'message' => "File too large. Maximum upload size is {$uploadMax}.",
                        'errors' => ['image' => ["Your file exceeds the {$uploadMax} upload limit. Please choose a smaller image."]]
                    ], 422);
                }

                return response()->json([
                    'message' => 'Please select an image file to upload.',
                    'errors' => ['image' => ['No image file was provided.']]
                ], 422);
            }

            $file = $request->file('image');

            // Check if file uploaded successfully
            if (!$file->isValid()) {
                $error = $file->getError();
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => 'File is too large for server configuration.',
                    UPLOAD_ERR_FORM_SIZE => 'File is too large.',
                    UPLOAD_ERR_PARTIAL => 'File upload was interrupted. Please try again.',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error. Please contact support.',
                    UPLOAD_ERR_CANT_WRITE => 'Server storage error. Please try again.',
                    UPLOAD_ERR_EXTENSION => 'File type not supported.',
                ];

                $message = $errorMessages[$error] ?? 'File upload failed. Please try again.';
                
                Log::warning('File upload error', [
                    'user_id' => $request->user()->id,
                    'error_code' => $error,
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);

                return response()->json([
                    'message' => $message,
                    'errors' => ['image' => [$message]]
                ], 422);
            }

            // Validate file
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg|max:10240', // 10MB max
            ], [
                'image.image' => 'Please upload a valid image file.',
                'image.mimes' => 'Only JPEG, PNG, and JPG images are supported.',
                'image.max' => 'Image must be smaller than 10MB.',
            ]);

            // Store image
            $imagePath = $file->store('receipts', 'public');

            if (!$imagePath) {
                Log::error('Failed to store uploaded file', [
                    'user_id' => $request->user()->id,
                    'file_name' => $file->getClientOriginalName(),
                ]);

                return response()->json([
                    'message' => 'Failed to save your image. Please try again.',
                    'errors' => ['image' => ['Storage error occurred.']]
                ], 500);
            }

            // Create receipt record
            $receipt = $request->user()->receipts()->create([
                'image_path' => $imagePath,
                'status' => 'processing',
                'week_of' => Carbon::now()->startOfWeek(),
            ]);

            // Dispatch AI processing job
            ProcessReceiptJob::dispatch($receipt);

            Log::info('Job dispatched', [
                'receipt_id' => $receipt->id,
                'queue_connection' => config('queue.default')
            ]);

            Log::info('Receipt uploaded and processing started', [
                'user_id' => $request->user()->id,
                'receipt_id' => $receipt->id,
                'file_size' => $file->getSize(),
            ]);

            return response()->json([
                'id' => $receipt->id,
                'status' => 'processing',
                'message' => 'Receipt uploaded successfully! We\'re extracting the data now.',
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Please check your image and try again.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Receipt upload failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'file_info' => $request->hasFile('image') ? [
                    'name' => $request->file('image')->getClientOriginalName(),
                    'size' => $request->file('image')->getSize(),
                ] : null,
            ]);

            return response()->json([
                'message' => 'Something went wrong while uploading your receipt. Please try again.',
                'errors' => ['image' => ['Upload failed. Please try again or contact support if the problem persists.']]
            ], 500);
        }
    }

    public function show(Receipt $receipt)
    {
        Gate::authorize('view', $receipt);

        $receipt->load(['items']);

        return response()->json($receipt);
    }

    public function destroy(Receipt $receipt)
    {
        Gate::authorize('delete', $receipt);

        // Delete image file
        if (Storage::disk('public')->exists($receipt->image_path)) {
            Storage::disk('public')->delete($receipt->image_path);
        }

        $receipt->delete();

        Log::info('Receipt deleted', [
            'user_id' => $receipt->user_id,
            'receipt_id' => $receipt->id,
        ]);

        return response()->json(['message' => 'Receipt deleted successfully']);
    }

    public function queueStatus()
    {
        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();
        
        return response()->json([
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobs,
            'queue_connection' => config('queue.default')
        ]);
    }

    public function getAnonymousReceipt($sessionId, $receiptId)
    {
        $receipt = Receipt::where('session_id', $sessionId)
                        ->where('id', $receiptId)
                        ->whereNull('user_id')
                        ->with('items')
                        ->first();

        if (!$receipt) {
            return response()->json([
                'message' => 'Receipt not found or access denied.'
            ], 404);
        }

        return response()->json($receipt);
    }
}