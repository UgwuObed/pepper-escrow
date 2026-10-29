<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\PaymentGateways\VfdGateway;
use App\Services\EscrowPaymentService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Backs the bank picker and the beneficiary confirmation on the payment
 * creation screens.
 */
class BankController extends Controller
{
    protected EscrowPaymentService $paymentService;

    public function __construct(EscrowPaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Live bank list for the picker. Cached for a day — the NIBSS list barely
     * moves and every escrow creation hits this screen.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$this->paymentService->hasGateway('vfd')) {
            Log::error('Bank list requested but VFD gateway is not configured');

            return response()->json([
                'status' => 'error',
                'message' => 'Bank list is temporarily unavailable.',
            ], 503);
        }

        try {
            $banks = Cache::remember('vfd.bank_list', now()->addDay(), function () {
                return $this->vfd()->getBanks();
            });

            $search = trim((string) $request->query('search', ''));

            if ($search !== '') {
                $banks = array_values(array_filter(
                    $banks,
                    fn ($bank) => stripos($bank['name'], $search) !== false
                ));
            }

            return response()->json([
                'status' => 'success',
                'data' => $banks,
            ]);
        } catch (Exception $e) {
            Log::error('Bank list error', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to retrieve bank list.',
            ], 502);
        }
    }

    /**
     * Resolve an account number to its owner's name — the green confirmation
     * row on the payment screen.
     *
     * Rate limited per API token: an open name-enquiry endpoint is an account
     * -name harvesting tool if left unmetered.
     */
    public function resolveAccount(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'account_number' => 'required|string|digits:10',
            'bank_code' => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $accountNumber = $request->input('account_number');
        $bankCode = $request->input('bank_code');

        // Checked up front so a missing VFD credential reports itself as an
        // outage rather than telling the caller their account number is wrong.
        if (!$this->paymentService->hasGateway('vfd')) {
            Log::error('Name enquiry attempted but VFD gateway is not configured');

            return response()->json([
                'status' => 'error',
                'message' => 'Account verification is temporarily unavailable.',
            ], 503);
        }

        try {
            // Identical lookups are common (the screen re-validates as the user
            // edits other fields), so brief caching keeps VFD calls down.
            $cacheKey = 'vfd.name_enquiry.' . $bankCode . '.' . $accountNumber;

            $resolved = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($accountNumber, $bankCode) {
                return $this->vfd()->nameEnquiry($accountNumber, $bankCode);
            });

            return response()->json([
                'status' => 'success',
                'data' => [
                    'account_number' => $resolved['account_number'],
                    'account_name' => $resolved['account_name'],
                    'bank_code' => $resolved['bank_code'],
                    'bank_name' => $resolved['bank_name'],
                ],
            ]);
        } catch (Exception $e) {
            Log::info('Name enquiry failed', [
                'bank_code' => $bankCode,
                'error' => $e->getMessage(),
            ]);

            // Do not leak VFD's wording — an unresolvable account and a
            // provider outage should look the same to a caller probing numbers.
            return response()->json([
                'status' => 'error',
                'message' => 'Could not resolve that account. Check the number and bank.',
            ], 422);
        }
    }

    protected function vfd(): VfdGateway
    {
        $gateway = $this->paymentService->getGateway('vfd');

        if (!$gateway instanceof VfdGateway) {
            throw new \RuntimeException('VFD gateway is not configured.');
        }

        return $gateway;
    }
}
