<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('🔍 EnsureEmailIsVerified Middleware Hit', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'is_admin_route' => $request->is('admin*'),
            'route_name' => $request->route()?->getName(),
            'user_id' => $request->user()?->id,
            'user_email' => $request->user()?->email,
            'user_verified' => $request->user()?->hasVerifiedEmail(),
        ]);

        // Skip email verification for admin routes
        if ($request->is('admin*')) {
            Log::info('✅ Skipping email verification for admin route', [
                'url' => $request->fullUrl(),
                'user_id' => $request->user()?->id,
            ]);
            return $next($request);
        }
        
        $user = $request->user();

        if (!$user) {
            Log::warning('❌ No authenticated user found', [
                'url' => $request->fullUrl(),
                'headers' => $request->headers->all(),
            ]);
            return response()->json([
                'message' => 'Authentication required.',
                'error' => 'unauthenticated'
            ], 401);
        }

        if (!$user->hasVerifiedEmail()) {
            Log::warning('❌ User email not verified', [
                'url' => $request->fullUrl(),
                'user_id' => $user->id,
                'user_email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
            ]);
            return response()->json([
                'message' => 'Email verification required to access this feature.',
                'error' => 'email_not_verified',
                'verification_required' => true
            ], 403);
        }

        Log::info('✅ Email verification passed', [
            'url' => $request->fullUrl(),
            'user_id' => $user->id,
        ]);

        return $next($request);
    }
}