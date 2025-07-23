<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class S3UploadController extends Controller
{
    public function getPresignedUrl(Request $request)
    {
        $request->validate([
            'filename' => 'required|string',
            'content_type' => 'required|string|starts_with:image/',
            'file_size' => 'required|integer|max:20971520', // 20MB max
        ]);

        try {
            // Generate unique filename
            $extension = pathinfo($request->filename, PATHINFO_EXTENSION);
            $filename = 'receipts/' . $request->user()->id . '/' . Str::uuid() . '.' . $extension;

            // Use AWS SDK directly for better compatibility
            $s3 = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => config('filesystems.disks.s3.region'),
                'credentials' => [
                    'key' => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ],
            ]);

            $cmd = $s3->getCommand('PutObject', [
                'Bucket' => config('filesystems.disks.s3.bucket'),
                'Key' => $filename,
                'ContentType' => $request->content_type,
            ]);

            $presignedUrl = (string) $s3->createPresignedRequest($cmd, '+10 minutes')->getUri();

            Log::info('Generated presigned URL', [
                'user_id' => $request->user()->id,
                'filename' => $filename,
                'file_size' => $request->file_size,
            ]);

            return response()->json([
                'presigned_url' => $presignedUrl,
                'file_key' => $filename,
                'expires_in' => 600, // 10 minutes
            ]);

        } catch (\Exception $e) {
            Log::error('S3 presigned URL generation failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'filename' => $request->filename,
            ]);

            return response()->json([
                'message' => 'Failed to generate upload URL. Please try again.',
                'error' => 'Upload preparation failed'
            ], 500);
        }
    }

    public function confirmUpload(Request $request)
    {
        $request->validate([
            'files' => 'required|array|min:1|max:10', // Allow up to 10 files
            'files.*.file_key' => 'required|string',
            'files.*.original_name' => 'required|string',
            'files.*.file_size' => 'required|integer',
        ]);

        try {
            $receipts = [];
            
            foreach ($request->input('files') as $fileData) {
                // Verify file exists in S3
                if (!Storage::disk('s3')->exists($fileData['file_key'])) {
                    return response()->json([
                        'message' => 'One or more files were not uploaded successfully.',
                        'error' => 'File verification failed'
                    ], 400);
                }

                // Create receipt record
                $receipt = $request->user()->receipts()->create([
                    'image_path' => $fileData['file_key'], // S3 key instead of local path
                    'original_filename' => $fileData['original_name'],
                    'file_size' => $fileData['file_size'],
                    'storage_disk' => 's3',
                    'status' => 'processing',
                    'week_of' => Carbon::now()->startOfWeek(),
                ]);

                // Dispatch AI processing job
                \App\Jobs\ProcessReceiptJob::dispatch($receipt);

                $receipts[] = [
                    'id' => $receipt->id,
                    'status' => 'processing',
                    'original_filename' => $fileData['original_name'],
                ];
            }

            Log::info('Multiple receipts uploaded successfully', [
                'user_id' => $request->user()->id,
                'receipt_count' => count($receipts),
                'receipt_ids' => collect($receipts)->pluck('id'),
            ]);

            return response()->json([
                'message' => count($receipts) === 1 
                    ? 'Receipt uploaded successfully! We\'re extracting the data now.' 
                    : count($receipts) . ' receipts uploaded successfully! We\'re processing them now.',
                'receipts' => $receipts,
                'total_uploaded' => count($receipts),
            ], 201);

        } catch (\Exception $e) {
            Log::error('Receipt confirmation failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'files_count' => count($request->input('files', [])),
            ]);

            return response()->json([
                'message' => 'Failed to process uploaded receipts. Please try again.',
                'error' => 'Processing failed'
            ], 500);
        }
    }

    // ANONYMOUS UPLOAD METHODS

    public function getPresignedUrlAnonymous(Request $request)
    {
        $request->validate([
            'filename' => 'required|string',
            'content_type' => 'required|string|starts_with:image/',
            'file_size' => 'required|integer|max:20971520', // 20MB max
            'session_id' => 'required|string|max:100',
        ]);

        try {
            $sessionId = $request->input('session_id');
            
            // Generate unique filename for anonymous user
            $extension = pathinfo($request->filename, PATHINFO_EXTENSION);
            $filename = 'receipts/anonymous/' . $sessionId . '/' . Str::uuid() . '.' . $extension;

            // Use AWS SDK directly for better compatibility
            $s3 = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => config('filesystems.disks.s3.region'),
                'credentials' => [
                    'key' => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ],
            ]);

            $cmd = $s3->getCommand('PutObject', [
                'Bucket' => config('filesystems.disks.s3.bucket'),
                'Key' => $filename,
                'ContentType' => $request->content_type,
            ]);

            $presignedUrl = (string) $s3->createPresignedRequest($cmd, '+10 minutes')->getUri();

            Log::info('Generated anonymous presigned URL', [
                'session_id' => $sessionId,
                'filename' => $filename,
                'file_size' => $request->file_size,
            ]);

            return response()->json([
                'presigned_url' => $presignedUrl,
                'file_key' => $filename,
                'expires_in' => 600, // 10 minutes
            ]);

        } catch (\Exception $e) {
            Log::error('Anonymous S3 presigned URL generation failed', [
                'session_id' => $request->input('session_id'),
                'error' => $e->getMessage(),
                'filename' => $request->filename,
            ]);

            return response()->json([
                'message' => 'Failed to generate upload URL. Please try again.',
                'error' => 'Upload preparation failed'
            ], 500);
        }
    }

    public function confirmUploadAnonymous(Request $request)
    {
        $request->validate([
            'files' => 'required|array|min:1|max:3', // Limit anonymous users to 3 files
            'files.*.file_key' => 'required|string',
            'files.*.original_name' => 'required|string',
            'files.*.file_size' => 'required|integer',
            'session_id' => 'required|string|max:100',
        ]);

        try {
            $sessionId = $request->input('session_id');
            
            // Check anonymous upload limits (3 total uploads per session)
            $existingCount = \App\Models\Receipt::where('session_id', $sessionId)
                                                ->whereNull('user_id')
                                                ->count();
            
            $newCount = count($request->input('files'));
            
            if ($existingCount + $newCount > 3) {
                return response()->json([
                    'message' => 'You have reached the limit of 3 uploads. Please sign up to upload more!',
                    'error' => 'upload_limit_reached',
                    'upgrade_required' => true,
                    'current_count' => $existingCount,
                    'limit' => 3
                ], 429);
            }

            $receipts = [];
            
            foreach ($request->input('files') as $fileData) {
                // Verify file exists in S3
                if (!Storage::disk('s3')->exists($fileData['file_key'])) {
                    return response()->json([
                        'message' => 'One or more files were not uploaded successfully.',
                        'error' => 'File verification failed'
                    ], 400);
                }

                // Create receipt record for anonymous user
                $receipt = \App\Models\Receipt::create([
                    'user_id' => null, // No user yet
                    'session_id' => $sessionId,
                    'image_path' => $fileData['file_key'],
                    'original_filename' => $fileData['original_name'],
                    'file_size' => $fileData['file_size'],
                    'storage_disk' => 's3',
                    'status' => 'processing',
                    'week_of' => Carbon::now()->startOfWeek(),
                ]);

                // Dispatch AI processing job
                \App\Jobs\ProcessReceiptJob::dispatch($receipt);

                $receipts[] = [
                    'id' => $receipt->id,
                    'status' => 'processing',
                    'original_filename' => $fileData['original_name'],
                ];
            }

            $remainingUploads = 3 - ($existingCount + count($receipts));

            Log::info('Anonymous receipts uploaded successfully', [
                'session_id' => $sessionId,
                'receipt_count' => count($receipts),
                'total_for_session' => $existingCount + count($receipts),
                'receipt_ids' => collect($receipts)->pluck('id'),
            ]);

            return response()->json([
                'message' => count($receipts) === 1 
                    ? 'Receipt uploaded successfully! We\'re extracting the data now.' 
                    : count($receipts) . ' receipts uploaded successfully! We\'re processing them now.',
                'receipts' => $receipts,
                'total_uploaded' => count($receipts),
                'remaining_uploads' => $remainingUploads,
                'signup_prompt' => $remainingUploads === 0 ? 'You\'ve used all your free uploads! Sign up for unlimited uploads.' : null,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Anonymous receipt confirmation failed', [
                'session_id' => $request->input('session_id'),
                'error' => $e->getMessage(),
                'files_count' => count($request->input('files', [])),
            ]);

            return response()->json([
                'message' => 'Failed to process uploaded receipts. Please try again.',
                'error' => 'Processing failed'
            ], 500);
        }
    }
}