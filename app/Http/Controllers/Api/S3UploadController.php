<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Models\Receipt;
use App\Jobs\ProcessReceiptJob;

class S3UploadController extends Controller
{
    // Add middleware for upload limits
    public function __construct()
    {
        $this->middleware('upload.limits')->only(['getPresignedUrl', 'confirmUpload']);
    }

    public function getPresignedUrl(Request $request)
    {
        $request->validate([
            'filename' => 'required|string|max:255',
            'content_type' => 'required|string|starts_with:image/',
            'file_size' => 'required|integer|min:1|max:20971520', // 20MB max
        ]);

        try {
            $user = $request->user();
            
            // Generate unique filename with better organization
            $extension = strtolower(pathinfo($request->filename, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            
            if (!in_array($extension, $allowedExtensions)) {
                return response()->json([
                    'message' => 'Invalid file type. Please upload an image file.',
                    'error' => 'invalid_file_type'
                ], 400);
            }

            $filename = sprintf(
                'receipts/%d/%s/%s.%s',
                $user->id,
                Carbon::now()->format('Y/m'),
                Str::uuid(),
                $extension
            );

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
                'Metadata' => [
                    'user_id' => (string) $user->id,
                    'uploaded_at' => Carbon::now()->toISOString(),
                    'original_filename' => $request->filename,
                ],
            ]);

            $presignedUrl = (string) $s3->createPresignedRequest($cmd, '+10 minutes')->getUri();

            Log::info('Generated presigned URL', [
                'user_id' => $user->id,
                'filename' => $filename,
                'file_size' => $request->file_size,
                'content_type' => $request->content_type,
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
                'trace' => $e->getTraceAsString(),
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
            'files.*.original_name' => 'required|string|max:255',
            'files.*.file_size' => 'required|integer|min:1',
        ]);

        try {
            $user = $request->user();
            $receipts = [];
            $uploadedCount = 0;
            
            foreach ($request->input('files') as $fileData) {
                // Verify file exists in S3
                if (!Storage::disk('s3')->exists($fileData['file_key'])) {
                    Log::warning('File not found in S3', [
                        'user_id' => $user->id,
                        'file_key' => $fileData['file_key'],
                    ]);
                    continue; // Skip this file but don't fail the entire upload
                }

                // Create receipt record
                $receipt = $user->receipts()->create([
                    'image_path' => $fileData['file_key'],
                    'original_filename' => $fileData['original_name'],
                    'file_size' => $fileData['file_size'],
                    'storage_disk' => 's3',
                    'status' => 'processing',
                    'week_of' => Carbon::now()->startOfWeek(),
                ]);

                // Dispatch AI processing job
                ProcessReceiptJob::dispatch($receipt);

                $receipts[] = [
                    'id' => $receipt->id,
                    'status' => 'processing',
                    'original_filename' => $fileData['original_name'],
                ];
                
                $uploadedCount++;
            }

            if ($uploadedCount === 0) {
                return response()->json([
                    'message' => 'No files were successfully uploaded.',
                    'error' => 'No valid files found'
                ], 400);
            }

            // Get user's current upload stats for response
            $currentMonthUploads = $user->receipts()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            $uploadLimit = $user->getUploadLimit();
            
            Log::info('Multiple receipts uploaded successfully', [
                'user_id' => $user->id,
                'receipt_count' => $uploadedCount,
                'receipt_ids' => collect($receipts)->pluck('id'),
                'current_month_total' => $currentMonthUploads,
            ]);

            $response = [
                'message' => $uploadedCount === 1 
                    ? 'Receipt uploaded successfully! We\'re extracting the data now.' 
                    : $uploadedCount . ' receipts uploaded successfully! We\'re processing them now.',
                'receipts' => $receipts,
                'total_uploaded' => $uploadedCount,
                'user_id' => $user->id,
            ];

            // Only add remaining uploads if user has limits
            if ($uploadLimit !== -1) {
                $response['remaining_uploads'] = max(0, $uploadLimit - $currentMonthUploads);
            }

            return response()->json($response, 201);

        } catch (\Exception $e) {
            Log::error('Receipt confirmation failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'files_count' => count($request->input('files', [])),
                'trace' => $e->getTraceAsString(),
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
            'filename' => 'required|string|max:255',
            'content_type' => 'required|string|starts_with:image/',
            'file_size' => 'required|integer|min:1|max:20971520', // 20MB max
            'session_id' => 'required|string|max:100',
        ]);

        try {
            $sessionId = $request->input('session_id');
            
            // Check anonymous upload limits before generating presigned URL
            $existingCount = Receipt::where('session_id', $sessionId)
                                   ->whereNull('user_id')
                                   ->count();
            
            if ($existingCount >= 3) {
                return response()->json([
                    'message' => 'You have reached the limit of 3 uploads. Please sign up to upload more!',
                    'error' => 'upload_limit_reached',
                    'upgrade_required' => true,
                    'current_count' => $existingCount,
                    'limit' => 3
                ], 429);
            }
            
            // Generate unique filename for anonymous user
            $extension = strtolower(pathinfo($request->filename, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            
            if (!in_array($extension, $allowedExtensions)) {
                return response()->json([
                    'message' => 'Invalid file type. Please upload an image file.',
                    'error' => 'invalid_file_type'
                ], 400);
            }

            $filename = sprintf(
                'receipts/anonymous/%s/%s/%s.%s',
                $sessionId,
                Carbon::now()->format('Y/m'),
                Str::uuid(),
                $extension
            );

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
                'Metadata' => [
                    'session_id' => $sessionId,
                    'uploaded_at' => Carbon::now()->toISOString(),
                    'original_filename' => $request->filename,
                    'user_type' => 'anonymous',
                ],
            ]);

            $presignedUrl = (string) $s3->createPresignedRequest($cmd, '+10 minutes')->getUri();

            Log::info('Generated anonymous presigned URL', [
                'session_id' => $sessionId,
                'filename' => $filename,
                'file_size' => $request->file_size,
                'existing_count' => $existingCount,
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
                'trace' => $e->getTraceAsString(),
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
            'files.*.original_name' => 'required|string|max:255',
            'files.*.file_size' => 'required|integer|min:1',
            'session_id' => 'required|string|max:100',
        ]);

        try {
            $sessionId = $request->input('session_id');
            
            // Check anonymous upload limits (3 total uploads per session)
            $existingCount = Receipt::where('session_id', $sessionId)
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
            $uploadedCount = 0;
            
            foreach ($request->input('files') as $fileData) {
                // Verify file exists in S3
                if (!Storage::disk('s3')->exists($fileData['file_key'])) {
                    Log::warning('Anonymous file not found in S3', [
                        'session_id' => $sessionId,
                        'file_key' => $fileData['file_key'],
                    ]);
                    continue; // Skip this file but don't fail the entire upload
                }

                // Create receipt record for anonymous user
                $receipt = Receipt::create([
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
                ProcessReceiptJob::dispatch($receipt);

                $receipts[] = [
                    'id' => $receipt->id,
                    'status' => 'processing',
                    'original_filename' => $fileData['original_name'],
                ];
                
                $uploadedCount++;
            }

            if ($uploadedCount === 0) {
                return response()->json([
                    'message' => 'No files were successfully uploaded.',
                    'error' => 'No valid files found'
                ], 400);
            }

            $totalForSession = $existingCount + $uploadedCount;
            $remainingUploads = max(0, 3 - $totalForSession);

            Log::info('Anonymous receipts uploaded successfully', [
                'session_id' => $sessionId,
                'receipt_count' => $uploadedCount,
                'total_for_session' => $totalForSession,
                'receipt_ids' => collect($receipts)->pluck('id'),
            ]);

            $response = [
                'message' => $uploadedCount === 1 
                    ? 'Receipt uploaded successfully! We\'re extracting the data now.' 
                    : $uploadedCount . ' receipts uploaded successfully! We\'re processing them now.',
                'receipts' => $receipts,
                'total_uploaded' => $uploadedCount,
                'remaining_uploads' => $remainingUploads,
                'session_id' => $sessionId,
            ];

            // Add signup prompt when appropriate
            if ($remainingUploads === 0) {
                $response['signup_prompt'] = 'You\'ve used all your free uploads! Sign up for unlimited uploads and advanced features.';
            } elseif ($remainingUploads === 1) {
                $response['signup_prompt'] = 'Only 1 upload remaining! Sign up for unlimited uploads.';
            }

            return response()->json($response, 201);

        } catch (\Exception $e) {
            Log::error('Anonymous receipt confirmation failed', [
                'session_id' => $request->input('session_id'),
                'error' => $e->getMessage(),
                'files_count' => count($request->input('files', [])),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to process uploaded receipts. Please try again.',
                'error' => 'Processing failed'
            ], 500);
        }
    }
}