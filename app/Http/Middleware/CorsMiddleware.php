<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('🌐 CORS Middleware Hit', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'is_admin_route' => $request->is('admin*'),
            'origin' => $request->headers->get('Origin'),
            'route_name' => $request->route()?->getName(),
        ]);

        // Skip CORS for admin routes (Filament)
        if ($request->is('admin*')) {
            Log::info('✅ Skipping CORS for admin route', [
                'url' => $request->fullUrl(),
            ]);
            return $next($request);
        }

        $allowedOrigins = [
            'https://www.expenzai.app',
            'https://expenzai.app',
            'http://localhost:3000',
            'http://localhost:3001' 
        ];
        
        $origin = $request->headers->get('Origin');
        $allowedOrigin = in_array($origin, $allowedOrigins) ? $origin : $allowedOrigins[0];

        Log::info('🌐 Processing CORS for non-admin route', [
            'url' => $request->fullUrl(),
            'origin' => $origin,
            'allowed_origin' => $allowedOrigin,
        ]);

        // Handle preflight OPTIONS requests
        if ($request->isMethod('OPTIONS')) {
            Log::info('🌐 Handling OPTIONS preflight request', [
                'url' => $request->fullUrl(),
                'origin' => $origin,
            ]);
            return response('', 200)
                ->header('Access-Control-Allow-Origin', $allowedOrigin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept')
                ->header('Access-Control-Allow-Credentials', 'true');
        }

        $response = $next($request);

        return $response
            ->header('Access-Control-Allow-Origin', $allowedOrigin)
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept')
            ->header('Access-Control-Allow-Credentials', 'true');
    }
}