<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class MerchantAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::guard('merchant')->check()) {
            return redirect()->route('merchant.login');
        }

        return $next($request);
    }
}
