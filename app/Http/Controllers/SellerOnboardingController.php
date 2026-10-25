<?php

namespace App\Http\Controllers;

use App\Models\ConnectedAccount;
use App\Models\Merchant;
use App\Services\ConnectedAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * A hosted page a seller can start from themselves.
 *
 * Partners hand out /connect/{token}. The seller gives an email and a
 * country, and is sent straight into Stripe's own onboarding.
 *
 * This route is public and creates Stripe accounts, which makes it an abuse
 * surface. Three things hold it down: the partner opts in, the route is rate
 * limited, and a seller who comes back with an email we already know is sent
 * to finish the existing account rather than given a new one.
 */
class SellerOnboardingController extends Controller
{
    /** Countries Stripe supports for this configuration today. */
    public const COUNTRIES = [
        'GB' => 'United Kingdom',
        'US' => 'United States',
    ];

    protected ConnectedAccountService $accounts;

    public function __construct(ConnectedAccountService $accounts)
    {
        $this->accounts = $accounts;
    }

    public function show(string $token)
    {
        $merchant = $this->merchant($token);

        return view('connect.start', [
            'merchant' => $merchant,
            'token' => $token,
            'countries' => self::COUNTRIES,
        ]);
    }

    public function start(Request $request, string $token)
    {
        $merchant = $this->merchant($token);

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:191',
            'country' => 'required|in:' . implode(',', array_keys(self::COUNTRIES)),
        ], [
            'email.required' => 'We need an email address to set up your account.',
            'email.email' => 'That does not look like a valid email address.',
            'country.in' => 'We cannot pay sellers in that country yet.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $email = strtolower(trim($request->input('email')));

        try {
            // Someone who started and did not finish should land back in the
            // same account, not collect a second one. Stripe charges nothing
            // for abandoned accounts but a seller with two is a support call.
            $existing = ConnectedAccount::byMerchant($merchant->id)
                ->where('seller_email', $email)
                ->first();

            $account = $existing ?: $this->accounts->onboard($merchant, $email, $request->input('country'));

            if ($existing && $existing->charges_enabled) {
                return view('connect.done', [
                    'merchant' => $merchant,
                    'alreadyComplete' => true,
                ]);
            }

            $link = $this->accounts->onboardingLink(
                $account,
                route('connect.refresh', [$token, $account->account_id]),
                route('connect.done', [$token, $account->account_id]),
            );

            return redirect()->away($link['url']);
        } catch (\Throwable $e) {
            Log::error('Hosted seller onboarding failed', [
                'merchant' => $merchant->id,
                'error' => $e->getMessage(),
            ]);

            return back()
                ->withErrors(['email' => 'We could not start your account setup. Please try again in a moment.'])
                ->withInput();
        }
    }

    /**
     * Stripe sends the seller here when a link expires mid-flow. Minting a
     * fresh one and bouncing them straight back is better than showing a
     * dead end they have to understand.
     */
    public function refresh(string $token, string $accountId)
    {
        $merchant = $this->merchant($token);

        $account = ConnectedAccount::byMerchant($merchant->id)
            ->where('account_id', $accountId)
            ->firstOrFail();

        try {
            return redirect()->away($this->accounts->onboardingLink(
                $account,
                route('connect.refresh', [$token, $accountId]),
                route('connect.done', [$token, $accountId]),
            )['url']);
        } catch (\Throwable $e) {
            Log::error('Onboarding refresh failed', ['account' => $accountId, 'error' => $e->getMessage()]);

            return view('connect.start', [
                'merchant' => $merchant,
                'token' => $token,
                'countries' => self::COUNTRIES,
                'problem' => 'Your setup link expired and we could not make a new one. Please start again.',
            ]);
        }
    }

    /**
     * Where Stripe returns the seller when they finish.
     *
     * Returning here does not mean Stripe is satisfied — it only means the
     * seller left the flow. The account state is the truth, so it is synced
     * and reported rather than assumed.
     */
    public function done(string $token, string $accountId)
    {
        $merchant = $this->merchant($token);

        $account = ConnectedAccount::byMerchant($merchant->id)
            ->where('account_id', $accountId)
            ->firstOrFail();

        try {
            $account = $this->accounts->syncStatus($account);
        } catch (\Throwable $e) {
            Log::warning('Could not sync after onboarding return', [
                'account' => $accountId,
                'error' => $e->getMessage(),
            ]);
        }

        return view('connect.done', [
            'merchant' => $merchant,
            'account' => $account,
            'alreadyComplete' => false,
        ]);
    }

    protected function merchant(string $token): Merchant
    {
        $merchant = Merchant::where('seller_onboarding_token', $token)
            ->where('seller_onboarding_enabled', true)
            ->first();

        abort_if(!$merchant, 404);

        return $merchant;
    }
}
