<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUploadLimits
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Allow anonymous users (handle this in upload logic)
        if (!$user) {
            return $next($request);
        }

        // Check if user can upload
        if (!$user->canUpload()) {
            $remaining = $user->getRemainingUploads();
            $tier = $user->user_tier;
            
            $message = $tier === 'free' 
                ? 'You have reached your limit of 3 total uploads. Please sign up for unlimited daily uploads!'
                : 'You have reached your daily limit of 3 uploads. Try again tomorrow or upgrade to Pro for unlimited uploads!';

            return response()->json([
                'message' => $message,
                'error' => 'upload_limit_reached',
                'user_tier' => $tier,
                'remaining_uploads' => $remaining,
                'limit_type' => $tier === 'free' ? 'total' : 'daily',
                'upgrade_required' => true
            ], 429);
        }

        return $next($request);
    }
}
