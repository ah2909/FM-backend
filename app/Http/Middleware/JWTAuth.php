<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JWTAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $publicKey = $this->getPublicKey();
            $decoded = JWT::decode($token, $publicKey);
            $request->attributes->set('user', $decoded);
            return $next($request);
        } catch (\Exception $e) {
            Log::error('JWT decode error: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid token'], 403);
        }

    }

    private function getPublicKey()
    {
        $jwk = Cache::remember('jwks_public_key', 60*24, function () {
            $response = Http::get(config('app.auth_service_url') . '/.well-known/jwks.json');
            $jwks = $response->json();
            if (empty($jwks['keys'])) {
                throw new \Exception('No keys found in JWKS response');
            }
            return $jwks['keys'][0]; // Select the first key
        });
        $pem = JWK::parseKey($jwk, 'RS256');
        return $pem;
    }
}