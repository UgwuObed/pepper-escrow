<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = $request->header('Authorization');

        if (!$apiKey) {
            $apiKey = $request->input('api_key');
        }

        if (!$apiKey) {
            return response()->json([
                'ResponseStatus' => 'Unsuccessful',
                'ResponseCode' => 401,
                'ResponseMessage' => 'API key is required.'
            ], 401);
        }

        $apiKey = str_replace('Bearer ', '', $apiKey);

        $token = ApiToken::where('api_key', $apiKey)
            ->where('status', 1)
            ->first();

        if (!$token) {
            return response()->json([
                'ResponseStatus' => 'Unsuccessful',
                'ResponseCode' => 401,
                'ResponseMessage' => 'Invalid or inactive API key.'
            ], 401);
        }

        $request->merge(['api_token' => $token]);
        $request->setUserResolver(function () use ($token) {
            return $token;
        });

        return $next($request);
    }
}
