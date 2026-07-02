<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Merchant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showRegisterForm(): View
    {
        return view('merchant.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'business_name' => 'required|string|max:255',
            'email'         => 'required|email|unique:merchants,email',
            'phone'         => 'nullable|string|max:20',
            'website'       => 'nullable|url|max:255',
            'password'      => 'required|string|min:8|confirmed',
        ]);

        $merchant = Merchant::create([
            'business_name' => $validated['business_name'],
            'email'         => $validated['email'],
            'phone'         => $validated['phone'],
            'website'       => $validated['website'],
            'password'      => Hash::make($validated['password']),
            'status'        => 'active',
        ]);

        $apiKey = 'PEP_' . Str::random(32);
        $apiSecret = Str::random(64);

        ApiToken::create([
            'app_id'          => $merchant->id,
            'api_key'         => $apiKey,
            'api_secret'      => $apiSecret,
            'status'          => true,
            'payment_gateway' => 'paystack',
            'gateway_config'  => [],
            'merchant_id'     => $merchant->id,
        ]);

        return redirect()->route('merchant.login')
            ->with('success', 'Account created. Check your API keys on the dashboard after login.');
    }

    public function showLoginForm(): View
    {
        return view('merchant.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (Auth::guard('merchant')->attempt($credentials, $request->filled('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended(route('merchant.dashboard'));
        }

        return back()->withErrors(['email' => 'Invalid credentials.'])->onlyInput('email');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('merchant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('merchant.login');
    }
}
