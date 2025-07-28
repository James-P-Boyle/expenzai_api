<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
          // Skip email verification for admin routes
        if ($request->is('admin*')) {
            return $next($request);
        }
        
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Authentication required.',
                'error' => 'unauthenticated'
            ], 401);
        }

        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email verification required to access this feature.',
                'error' => 'email_not_verified',
                'verification_required' => true
            ], 403);
        }

        return $next($request);
    }
}
