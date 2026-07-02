<?php

namespace App\Http\Controllers\Escrow;

use App\Http\Controllers\Controller;
use App\Http\Resources\DisputeResource;
use App\Models\AppAccount;
use App\Models\Customer;
use App\Models\Dispute;
use App\Models\DisputeFile;
use App\Models\DisputeResolution;
use App\Models\ExtendedDate;
use App\Models\RequestLog;
use App\Models\RequestRefund;
use App\Models\Transaction;
use App\Models\TransactionHistory;
use App\Models\TransactionType;
use App\Repositories\ImageUtils;
use App\Services\CommissionService;
use App\Services\EscrowPaymentService;
use App\Services\RewardService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EscrowController extends Controller
{
    protected int $perPage = 6;
    protected ImageUtils $imageUtil;
    protected EscrowPaymentService $paymentService;
    protected CommissionService $commissionService;
    protected RewardService $rewardService;

    public function __construct(ImageUtils $imageUtil, EscrowPaymentService $paymentService, CommissionService $commissionService, RewardService $rewardService)
    {
        $this->imageUtil = $imageUtil;
        $this->paymentService = $paymentService;
        $this->commissionService = $commissionService;
        $this->rewardService = $rewardService;
    }

    protected function resolveTransactionType(?string $slug, ?string $appId): ?TransactionType
    {
        $slug = $slug ?? 'escrow';
        $merchant = \App\Models\Merchant::find($appId);
        if (!$merchant) {
            return null;
        }
        return TransactionType::where('merchant_id', $merchant->id)
            ->where('slug', $slug)
            ->first();
    }

    protected function getResponse(Transaction $tranx): array
    {
        $type = $tranx->transactionType;

        return [
            "createddate" => $tranx->posting_date,
            "transcode" => $tranx->transcode,
            "customer_email" => $tranx->customer_email,
            "merchant_email" => $tranx->merchant_email,
            "appid" => $tranx->appid,
            "description" => $tranx->description,
            "currency" => $tranx->currency,
            "country" => $tranx->country,
            "amount" => $tranx->amount,
            "startdate" => $tranx->startdate,
            "enddate" => $tranx->enddate,
            "fulfilldays" => $tranx->fulfill_days,
            "paymentdate" => $tranx->payment_date,
            "paymentstatus" => $tranx->payment_status,
            "requestrefund" => $tranx->request_refund,
            "requestextend" => $tranx->requestextend,
            "extended" => $tranx->extended,
            "refunded" => $tranx->refunded,
            "released" => !is_null($tranx->releasedate) ? 1 : 0,
            'releasedate' => $tranx->releasedate,
            "txnstatus" => $tranx->trans_status,
            "cancelleddate" => $tranx->cancelled_date,
            "fufillnoticedate" => $tranx->fufill_notice_date,
            "pepperestfee" => $tranx->pepperest_fee,
            "stoppaymentdate" => $tranx->stop_payment_date,
            "reasonforstopping" => $tranx->reason_for_stopping,
            "reasonforstoprefund" => $tranx->reason_for_stop_refund,
            "stoprefunddate" => $tranx->stop_refund_date,
            "refunddate" => $tranx->refund_date,
            "arbitrationrequestdate" => $tranx->arbitration_request_date,
            "payment_url" => $tranx->gateway_reference ? url("/escrow/pay/{$tranx->transcode}") : null,
            "transaction_type" => $type?->slug,
            "commission_amount" => (float) ($tranx->commission_amount ?? 0),
            "net_amount" => (float) ($tranx->net_amount ?? $tranx->amount),
        ];
    }

    protected function logRequest(Request $request, int $responseCode = 200): void
    {
        try {
            RequestLog::create([
                'uri' => $request->path(),
                'method' => $request->method(),
                'params' => json_encode($request->all()),
                'api_key' => $request->input('appid'),
                'ip_address' => $request->ip(),
                'time' => now()->timestamp,
                'request_date' => Carbon::now(),
                'authorized' => 1,
                'response_code' => $responseCode,
            ]);
        } catch (Exception $e) {
            // silently fail
        }
    }

    protected function logRequestError(Request $request): void
    {
        try {
            RequestLog::create([
                'uri' => $request->path(),
                'method' => $request->method(),
                'params' => json_encode($request->header()),
                'api_key' => $request->input('appid'),
                'ip_address' => $request->ip(),
                'time' => now()->timestamp,
                'request_date' => Carbon::now(),
                'authorized' => 0,
                'response_code' => 400,
            ]);
        } catch (Exception $e) {
            // silently fail
        }
    }

    /**
     * Create an escrow transaction (record only, no payment).
     */
    public function createTransaction(Request $request)
    {
        try {
            $rules = [
                'appid' => 'required|string',
                'referenceid' => 'string|required',
                'user_email' => 'email|required',
                'amount' => 'required|string',
                'country' => 'required|string',
                'currency' => 'required|string',
                'customer_email' => 'email|required',
                'merchant_email' => 'email|required',
                'description' => 'string|nullable',
                'customer_account_number' => 'required|string',
                'merchant_account_number' => 'required|string',
                'customer_bank_code' => 'required|string',
                'merchant_bank_code' => 'required|string',
                'customer_name' => 'string|nullable',
                'merchant_name' => 'string|nullable',
                'customer_phone' => 'string|nullable',
                'merchant_phone' => 'string|nullable',
                'peppfees' => 'required',
                'startdate' => 'required|string',
                'enddate' => 'required|string',
                'transfer_reference_id' => 'string|nullable',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                $this->logRequestError($request);
                return response()->json([
                    "ResponseStatus" => "Unsuccessful",
                    "ResponseCode" => 400,
                    "ResponseMessage" => $validator->errors(),
                ]);
            }

            $this->logRequest($request);

            $existing = Transaction::where('transcode', $request['referenceid'])->first();
            if (!is_null($existing)) {
                return response()->json([
                    "ResponseStatus" => "Unsuccessful: Referenceid already exist",
                    "ResponseCode" => 400,
                    "ResponseMessage" => [],
                ]);
            }

            $merchant = Customer::where('email', $request['merchant_email'])->first();
            $merchantId = $merchant?->id;

            // Resolve transaction type
            $type = $this->resolveTransactionType($request->input('transaction_type'), $request['appid']);

            $startdate = Carbon::parse($request['startdate']);
            $enddate = Carbon::parse($request['enddate']);
            $fulfillDays = $startdate->diffInDays($enddate);

            $tranx = Transaction::create([
                'posting_date' => Carbon::now(),
                'transcode' => $request['referenceid'],
                'customer_email' => $request['customer_email'],
                'merchant_email' => $request['merchant_email'],
                'merchantid' => $merchantId,
                'description' => $request['description'],
                'amount' => $request['amount'],
                'country' => $request['country'],
                'currency' => $request['currency'],
                'startdate' => $request['startdate'],
                'enddate' => $request['enddate'],
                'fulfill_days' => $fulfillDays . ' days',
                'payment_date' => Carbon::now(),
                'trans_status' => 'Open',
                'pepperest_fee' => $request['peppfees'],
                'appid' => $request['appid'],
                'transaction_type_id' => $type?->id,
                'metadata' => $request->input('metadata'),
            ]);

            // Apply commission if merchant and type exist
            if ($type && $merchantId) {
                try {
                    $merchantModel = \App\Models\Merchant::find($merchantId);
                    if ($merchantModel) {
                        $this->commissionService->applyToTransaction($tranx, $merchantModel);
                        $tranx = $tranx->fresh();
                    }
                } catch (\Exception $e) {
                    // Commission calculation is non-blocking
                }
            }

            if ($tranx) {
                AppAccount::create([
                    'appid' => $request['appid'],
                    'referenceid' => $request['referenceid'],
                    'customer_account' => $request['customer_account_number'],
                    'customer_code' => $request['customer_bank_code'],
                    'merchant_account' => $request['merchant_account_number'],
                    'merchant_code' => $request['merchant_bank_code'],
                ]);

                TransactionHistory::create([
                    'transcode' => $tranx->transcode,
                    'customer_email' => $tranx->customer_email,
                    'merchant_email' => $tranx->merchant_email,
                    'trans_status' => $tranx->trans_status,
                    'status_update_date' => Carbon::now(),
                    'updatedby' => 'API ' . $request['appid'],
                ]);

                $response = $this->getResponse($tranx);
                return response()->json([
                    "ResponseStatus" => "successful",
                    "ResponseCode" => 0,
                    "ResponseMessage" => $response,
                ]);
            }

            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => 'Something went wrong!',
            ]);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create an escrow transaction WITH payment link generation.
     * This uses the configured payment gateway to generate a checkout URL.
     */
    public function createCardTransaction(Request $request)
    {
        try {
            $rules = [
                'appid' => 'required|string',
                'referenceid' => 'string|required',
                'user_email' => 'email|required',
                'amount' => 'required|string',
                'country' => 'required|string',
                'currency' => 'required|string',
                'customer_email' => 'email|required',
                'merchant_email' => 'email|required',
                'description' => 'string|nullable',
                'merchant_account_number' => 'required|string',
                'merchant_bank_code' => 'required|string',
                'customer_name' => 'string|nullable',
                'merchant_name' => 'string|nullable',
                'customer_phone' => 'string|nullable',
                'merchant_phone' => 'string|nullable',
                'peppfees' => 'required',
                'startdate' => 'required|string',
                'enddate' => 'required|string',
                'callback_url' => 'nullable|string',
                'transfer_reference_id' => 'string|nullable',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                $this->logRequestError($request);
                return response()->json([
                    "ResponseStatus" => "Unsuccessful",
                    "ResponseCode" => 400,
                    "ResponseMessage" => $validator->errors(),
                ]);
            }

            $this->logRequest($request);

            $existing = Transaction::where('transcode', $request['referenceid'])->first();
            if (!is_null($existing)) {
                return response()->json([
                    "ResponseStatus" => "Unsuccessful: Referenceid already exist",
                    "ResponseCode" => 400,
                    "ResponseMessage" => [],
                ]);
            }

            $merchant = Customer::where('email', $request['merchant_email'])->first();
            $merchantId = $merchant?->id;

            // Resolve transaction type
            $type = $this->resolveTransactionType($request->input('transaction_type'), $request['appid']);

            $startdate = Carbon::parse($request['startdate']);
            $enddate = Carbon::parse($request['enddate']);
            $fulfillDays = $startdate->diffInDays($enddate);

            $tranx = Transaction::create([
                'posting_date' => Carbon::now(),
                'transcode' => $request['referenceid'],
                'customer_email' => $request['customer_email'],
                'merchant_email' => $request['merchant_email'],
                'merchantid' => $merchantId,
                'description' => $request['description'],
                'amount' => $request['amount'],
                'country' => $request['country'],
                'currency' => $request['currency'],
                'startdate' => $request['startdate'],
                'enddate' => $request['enddate'],
                'fulfill_days' => $fulfillDays . ' days',
                'trans_status' => 'PaymentPending',
                'pepperest_fee' => $request['peppfees'],
                'appid' => $request['appid'],
                'transaction_type_id' => $type?->id,
                'metadata' => $request->input('metadata'),
            ]);

            if ($tranx) {
                // Apply commission if merchant and type exist
                if ($type && $merchantId) {
                    try {
                        $merchantModel = \App\Models\Merchant::find($merchantId);
                        if ($merchantModel) {
                            $this->commissionService->applyToTransaction($tranx, $merchantModel);
                            $tranx = $tranx->fresh();
                        }
                    } catch (\Exception $e) {
                        // Commission calculation is non-blocking
                    }
                }
                AppAccount::create([
                    'appid' => $request['appid'],
                    'referenceid' => $request['referenceid'],
                    'merchant_account' => $request['merchant_account_number'],
                    'merchant_code' => $request['merchant_bank_code'],
                ]);

                TransactionHistory::create([
                    'transcode' => $tranx->transcode,
                    'customer_email' => $tranx->customer_email,
                    'merchant_email' => $tranx->merchant_email,
                    'trans_status' => 'PaymentPending',
                    'status_update_date' => Carbon::now(),
                    'updatedby' => 'API ' . $request['appid'],
                ]);

                // Generate payment link if callback URL provided
                $paymentUrl = null;
                if ($request->filled('callback_url')) {
                    try {
                        $token = $request->get('api_token');
                        $result = $this->paymentService->createTransactionWithPayment(
                            transactionData: $tranx->toArray(),
                            customerEmail: $request['customer_email'],
                            customerName: $request['customer_name'] ?? $request['customer_email'],
                            callbackUrl: $request['callback_url'],
                            token: $token,
                        );
                        $paymentUrl = $result['payment_url'];
                    } catch (Exception $e) {
                        // Transaction created but payment link failed
                    }
                }

                $response = $this->getResponse($tranx);
                $response['payment_url'] = $paymentUrl;

                return response()->json([
                    "ResponseStatus" => "successful",
                    "ResponseCode" => 0,
                    "ResponseMessage" => $response,
                ]);
            }

            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => 'Something went wrong!',
            ]);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate or re-generate a payment link for an existing transaction.
     */
    public function getPaymentLink(Request $request)
    {
        $rules = [
            'appid' => 'required|string',
            'referenceid' => 'string|required',
            'callback_url' => 'required|string',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => $validator->errors(),
            ]);
        }

        try {
            $tranx = Transaction::where([
                ['transcode', $request['referenceid']],
                ['appid', $request['appid']],
            ])->first();

            if (is_null($tranx)) {
                return response()->json([
                    "ResponseStatus" => "Unsuccessful",
                    "ResponseCode" => 404,
                    "ResponseMessage" => 'Transaction not found.',
                ], 404);
            }

            $token = $request->get('api_token');
            $result = $this->paymentService->createTransactionWithPayment(
                transactionData: $tranx->toArray(),
                customerEmail: $tranx->customer_email,
                customerName: $tranx->customer_email,
                callbackUrl: $request['callback_url'],
                token: $token,
            );

            return response()->json([
                "ResponseStatus" => "successful",
                "ResponseCode" => 0,
                "ResponseMessage" => [
                    'payment_url' => $result['payment_url'],
                    'reference' => $result['reference'],
                    'gateway' => $result['gateway'],
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle payment gateway webhook/callback.
     */
    public function handleWebhook(Request $request, string $gateway)
    {
        try {
            $reference = null;

            match ($gateway) {
                'paystack' => $reference = $request->input('data.reference') ?? $request->input('event.data.reference'),
                'stripe' => $reference = $request->input('data.object.client_reference_id'),
                'seerbit' => $reference = $request->input('paymentReference'),
                'flutterwave' => $reference = $request->input('data.tx_ref') ?? $request->input('txRef'),
                default => throw new \Exception("Unsupported gateway: {$gateway}"),
            };

            if (!$reference) {
                return response()->json(['status' => 'error', 'message' => 'No reference provided'], 400);
            }

            $result = $this->paymentService->verifyTransactionPayment($reference);

            // Auto-earn rewards on successful payment
            if ($result['status'] === 'success' || $result['status'] === 'successful') {
                $transaction = \App\Models\Transaction::where('transcode', $reference)->first();
                if ($transaction) {
                    $merchant = \App\Models\Merchant::find($transaction->appid);
                    if ($merchant) {
                        try {
                            $this->rewardService->earnOnTransaction($transaction, $merchant);
                        } catch (\Exception $e) {
                            // Reward earning is non-blocking
                        }
                    }
                }
            }

            // Notify merchant of payment received
            if ($result['status'] === 'success' || $result['status'] === 'successful') {
                $transaction = \App\Models\Transaction::where('transcode', $reference)->first();
                if ($transaction) {
                    $merchant = \App\Models\Merchant::find($transaction->appid);
                    if ($merchant) {
                        try {
                            $service = app(\App\Services\NotificationService::class);
                            $service->notifyPaymentReceived($merchant, [
                                'transcode' => $transaction->transcode,
                                'amount' => $transaction->amount,
                                'customer_email' => $transaction->customer_email,
                                'reference_type' => 'transaction',
                                'reference_id' => $transaction->id,
                            ]);
                        } catch (\Exception $e) {
                            // Notification is non-blocking
                        }
                    }
                }
            }

            return response()->json(['status' => 'success', 'data' => $result]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function stopTransaction(Request $request)
    {
        try {
            $rules = [
                'appid' => 'required|string',
                'referenceid' => 'string|required',
                'user_email' => 'email|required',
                'reasons' => 'required|string',
            ];

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $this->logRequestError($request);
                return response()->json([
                    "ResponseStatus" => "Unsuccessful",
                    "ResponseCode" => 400,
                    "ResponseMessage" => $validator->errors(),
                ]);
            }

            $this->logRequest($request);

            $tranx = Transaction::where([
                ['transcode', $request['referenceid']],
                ['appid', $request['appid']],
            ])->first();

            if (!is_null($tranx)) {
                if (
                    !is_null($tranx->cancelled_date) ||
                    !is_null($tranx->releasedate) ||
                    $tranx->refunded == 1 ||
                    !is_null($tranx->stop_payment_date) ||
                    !is_null($tranx->releasedate)
                ) {
                    $response = $this->getResponse($tranx);
                    return response()->json([
                        "ResponseStatus" => "Unsuccessful: transaction cancelled, released or refunded or released",
                        "ResponseCode" => 400,
                        "ResponseMessage" => $response,
                    ]);
                }

                $tranx->update([
                    "stop_payment_date" => Carbon::now(),
                    "reason_for_stopping" => $request['reasons'],
                    'trans_status' => 'Flagged',
                ]);

                TransactionHistory::create([
                    'transcode' => $tranx->transcode,
                    'customer_email' => $tranx->customer_email,
                    'merchant_email' => $tranx->merchant_email,
                    'trans_status' => $tranx->trans_status,
                    'status_update_date' => Carbon::now(),
                    'updatedby' => 'API ' . $request['appid'],
                ]);

                $response = $this->getResponse($tranx);
                return response()->json([
                    "ResponseStatus" => "successful",
                    "ResponseCode" => 0,
                    "ResponseMessage" => $response,
                ]);
            }

            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => 'Something went wrong!',
            ]);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    public function releaseTransaction(Request $request)
    {
        try {
            $rules = [
                'appid' => 'required|string',
                'referenceid' => 'string|required',
                'user_email' => 'email|required',
                'reasons' => 'required|string',
            ];

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $this->logRequestError($request);
                return response()->json([
                    "ResponseStatus" => "Unsuccessful",
                    "ResponseCode" => 400,
                    "ResponseMessage" => $validator->errors(),
                ]);
            }

            $this->logRequest($request);

            $tranx = Transaction::where([
                ['transcode', $request['referenceid']],
                ['appid', $request['appid']],
            ])->first();

            if (!is_null($tranx)) {
                if (
                    !is_null($tranx->cancelled_date) ||
                    $tranx->refunded == 1 ||
                    !is_null($tranx->stop_payment_date) ||
                    !is_null($tranx->releasedate)
                ) {
                    $response = $this->getResponse($tranx);
                    return response()->json([
                        "ResponseStatus" => "Unsuccessful: transaction cancelled or refunded or released",
                        "ResponseCode" => 400,
                        "ResponseMessage" => $response,
                    ]);
                }

                $tranx->update([
                    "releasedate" => Carbon::now(),
                    'trans_status' => 'Fulfilled',
                    'fufillnoticedate' => Carbon::now(),
                ]);

                TransactionHistory::create([
                    'transcode' => $tranx->transcode,
                    'customer_email' => $tranx->customer_email,
                    'merchant_email' => $tranx->merchant_email,
                    'trans_status' => $tranx->trans_status,
                    'status_update_date' => Carbon::now(),
                    'updatedby' => 'API ' . $request['appid'],
                ]);

                $response = $this->getResponse($tranx);
                return response()->json([
                    "ResponseStatus" => "successful",
                    "ResponseCode" => 0,
                    "ResponseMessage" => $response,
                ]);
            }

            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => 'Something went wrong!',
            ]);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    public function reqTransactionExtension(Request $request)
    {
        try {
            $rules = [
                'appid' => 'required|string',
                'referenceid' => 'string|required',
                'user_email' => 'email|required',
                'new_date' => 'string|required',
                'reasons' => 'string|required',
            ];

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $this->logRequestError($request);
                return response()->json([
                    "ResponseStatus" => "Unsuccessful",
                    "ResponseCode" => 400,
                    "ResponseMessage" => $validator->errors(),
                ]);
            }

            $this->logRequest($request);

            $tranx = Transaction::where([
                ['transcode', $request['referenceid']],
                ['appid', $request['appid']],
            ])->first();

            if (!is_null($tranx)) {
                if (
                    !is_null($tranx->cancelled_date) ||
                    $tranx->refunded == 1 ||
                    !is_null($tranx->releasedate) ||
                    $tranx->requestextend == 1 ||
                    $tranx->extended == 1
                ) {
                    $response = $this->getResponse($tranx);
                    return response()->json([
                        "ResponseStatus" => "Unsuccessful: transaction cancelled or refunded or released or extension requested or already extended",
                        "ResponseCode" => 400,
                        "ResponseMessage" => $response,
                    ]);
                }

                $tranx->update([
                    "requestextend" => 1,
                    "request_extend" => 1,
                ]);

                ExtendedDate::create([
                    'posting_date' => Carbon::now(),
                    'transcode' => $tranx->transcode,
                    'customer_email' => $tranx->customer_email,
                    'merchant_email' => $tranx->merchant_email,
                    'amount' => $tranx->amount,
                    'startdate' => $tranx->startdate,
                    'old_fulfill_date' => $tranx->enddate,
                    'new_fulfill_date' => $request['new_date'],
                    'date_request' => Carbon::now(),
                    'reasons' => $request['reasons'],
                    'requester' => 'API ' . $request['appid'],
                    'reject_reason' => 'null',
                ]);

                $response = $this->getResponse($tranx);
                return response()->json([
                    "ResponseStatus" => "successful",
                    "ResponseCode" => 0,
                    "ResponseMessage" => $response,
                ]);
            }

            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => 'Something went wrong!',
            ]);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    public function extendTransaction(Request $request)
    {
        try {
            $rules = [
                'appid' => 'required|string',
                'referenceid' => 'string|required',
                'user_email' => 'email|required',
                'action' => 'string|required',
                'reasons' => 'string|required',
            ];

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $this->logRequestError($request);
                return response()->json([
                    "ResponseStatus" => "Unsuccessful",
                    "ResponseCode" => 400,
                    "ResponseMessage" => $validator->errors(),
                ]);
            }

            $this->logRequest($request);

            $tranx = Transaction::where([
                ['transcode', $request['referenceid']],
                ['appid', $request['appid']],
            ])->first();

            if (!is_null($tranx)) {
                if (
                    !is_null($tranx->cancelled_date) ||
                    $tranx->refunded == 1 ||
                    $tranx->requestextend == 0 ||
                    !is_null($tranx->releasedate) ||
                    $tranx->extended == 1
                ) {
                    $response = $this->getResponse($tranx);
                    return response()->json([
                        "ResponseStatus" => "Unsuccessful: transaction cancelled or refunded or released or no request for extension",
                        "ResponseCode" => 400,
                        "ResponseMessage" => $response,
                    ]);
                }

                $accept = $request['action'] === 'accept';
                $tranx->update([
                    "extended" => $accept ? 1 : 0,
                    "trans_status" => $accept ? 'Open' : 'Closed',
                ]);

                ExtendedDate::where('transcode', $request['referenceid'])->update([
                    'request_status' => $accept ? 'accepted' : 'refused',
                    'reject_reason' => $request['reasons'],
                    'date_extended' => Carbon::now(),
                ]);

                TransactionHistory::create([
                    'transcode' => $tranx->transcode,
                    'customer_email' => $tranx->customer_email,
                    'merchant_email' => $tranx->merchant_email,
                    'trans_status' => $tranx->trans_status,
                    'status_update_date' => Carbon::now(),
                    'updatedby' => 'API ' . $request['appid'],
                ]);

                $response = $this->getResponse($tranx);
                return response()->json([
                    "ResponseStatus" => "successful",
                    "ResponseCode" => 0,
                    "ResponseMessage" => $response,
                ]);
            }

            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => 'Something went wrong!',
            ]);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    public function reqTransactionRefund(Request $request)
    {
        try {
            $rules = [
                'appid' => 'required|string',
                'referenceid' => 'string|required',
                'user_email' => 'email|required',
                'reasons' => 'string|required',
            ];

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $this->logRequestError($request);
                return response()->json([
                    "ResponseStatus" => "Unsuccessful",
                    "ResponseCode" => 400,
                    "ResponseMessage" => $validator->errors(),
                ]);
            }

            $this->logRequest($request);

            $tranx = Transaction::where([
                ['transcode', $request['referenceid']],
                ['appid', $request['appid']],
            ])->first();

            if (!is_null($tranx)) {
                if (
                    !is_null($tranx->cancelled_date) ||
                    $tranx->refunded == 1 ||
                    !is_null($tranx->releasedate)
                ) {
                    $response = $this->getResponse($tranx);
                    return response()->json([
                        "ResponseStatus" => "Unsuccessful: transaction cancelled or refunded or released",
                        "ResponseCode" => 400,
                        "ResponseMessage" => $response,
                    ]);
                }

                $tranx->update([
                    "requestrefund" => 1,
                    "request_refund" => 1,
                    'trans_status' => 'Flagged',
                ]);

                RequestRefund::create([
                    'posting_date' => Carbon::now(),
                    'transcode' => $tranx->transcode,
                    "customer_email" => $tranx->customer_email,
                    "merchant_email" => $tranx->merchant_email,
                    'amount' => $tranx->amount,
                    "startdate" => $tranx->startdate,
                    "enddate" => $tranx->enddate,
                    'date_request' => Carbon::now(),
                    'reasons' => $request['reasons'],
                    'request_status' => $tranx->trans_status,
                    'requester' => 'API ' . $request['appid'],
                    'reject_reason' => 'null',
                ]);

                TransactionHistory::create([
                    'transcode' => $tranx->transcode,
                    'customer_email' => $tranx->customer_email,
                    'merchant_email' => $tranx->merchant_email,
                    'trans_status' => $tranx->trans_status,
                    'status_update_date' => Carbon::now(),
                    'updatedby' => 'API ' . $request['appid'],
                ]);

                $response = $this->getResponse($tranx);
                return response()->json([
                    "ResponseStatus" => "successful",
                    "ResponseCode" => 0,
                    "ResponseMessage" => $response,
                ]);
            }

            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => 'Something went wrong!',
            ]);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    public function refundTransaction(Request $request)
    {
        try {
            $rules = [
                'appid' => 'required|string',
                'referenceid' => 'string|required',
                'action' => 'string|required',
                'user_email' => 'email|required',
                'reasons' => 'string|required',
            ];

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $this->logRequestError($request);
                return response()->json([
                    "ResponseStatus" => "Unsuccessful",
                    "ResponseCode" => 400,
                    "ResponseMessage" => $validator->errors(),
                ]);
            }

            $this->logRequest($request);

            $tranx = Transaction::where([
                ['transcode', $request['referenceid']],
                ['appid', $request['appid']],
            ])->first();

            if (!is_null($tranx)) {
                if (
                    !is_null($tranx->cancelled_date) ||
                    $tranx->refunded == 1 ||
                    !is_null($tranx->releasedate) ||
                    $tranx->request_refund == 0
                ) {
                    $response = $this->getResponse($tranx);
                    return response()->json([
                        "ResponseStatus" => "Unsuccessful: transaction was previously cancelled or released or already refunded or no previous RequestRefund",
                        "ResponseCode" => 400,
                        "ResponseMessage" => $response,
                    ]);
                }

                $accept = $request['action'] === 'accept';
                $tranx->update([
                    "refunded" => $accept ? 1 : 0,
                    "trans_status" => $accept ? 'Closed' : 'Open',
                ]);

                RequestRefund::where('transcode', $tranx->transcode)->update([
                    'request_status' => $accept ? 'accepted' : 'refused',
                    'requester' => 'API ' . $request['appid'],
                    'date_refunded' => Carbon::now(),
                    'reject_reason' => $request['reasons'],
                ]);

                // Process actual refund through payment gateway if accepted
                if ($accept && $tranx->payment_gateway) {
                    try {
                        $this->paymentService->processEscrowRefund($tranx, $request['reasons']);
                    } catch (Exception $e) {
                        // Log but don't fail — refund request is already recorded
                    }
                }

                TransactionHistory::create([
                    'transcode' => $tranx->transcode,
                    'customer_email' => $tranx->customer_email,
                    'merchant_email' => $tranx->merchant_email,
                    'trans_status' => $tranx->trans_status,
                    'status_update_date' => Carbon::now(),
                    'updatedby' => 'API ' . $request['appid'],
                ]);

                $response = $this->getResponse($tranx);
                return response()->json([
                    "ResponseStatus" => "successful",
                    "ResponseCode" => 0,
                    "ResponseMessage" => $response,
                ]);
            }

            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => 'Something went wrong!',
            ]);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    public function getAppTransactions(Request $request)
    {
        $rules = [
            'appid' => 'required|string',
            'action' => 'required|string',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $this->logRequestError($request);
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => $validator->errors(),
            ]);
        }

        try {
            $this->logRequest($request);

            $trans = Transaction::where([
                ['appid', $request['appid']],
                ['trans_status', $request['action']],
            ])->get();

            if (!is_null($trans)) {
                $response = $trans->map(fn($tranx) => $this->getResponse($tranx));

                return response()->json([
                    "ResponseStatus" => "successful",
                    "ResponseCode" => 0,
                    "ResponseMessage" => $response,
                ]);
            }

            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => 'Something went wrong!',
            ]);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    public function getAppTransactionByRef(Request $request)
    {
        try {
            $rules = [
                'appid' => 'required|string',
                'referenceid' => 'string|required',
                'user_email' => 'email|required',
            ];

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $this->logRequestError($request);
                return response()->json([
                    "ResponseStatus" => "Unsuccessful",
                    "ResponseCode" => 400,
                    "ResponseMessage" => $validator->errors(),
                ]);
            }

            $this->logRequest($request);

            $tranx = Transaction::where([
                ['transcode', $request['referenceid']],
                ['appid', $request['appid']],
            ])->first();

            if (!is_null($tranx)) {
                $response = $this->getResponse($tranx);
                return response()->json([
                    "ResponseStatus" => "successful",
                    "ResponseCode" => 0,
                    "ResponseMessage" => $response,
                ]);
            }

            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => 'Something went wrong!',
            ]);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    public function getBankCodes(Request $request)
    {
        try {
            $path = resource_path('assets/data/pepperest_countries.json');
            if (!file_exists($path)) {
                return response()->json([
                    "ResponseStatus" => "Unsuccessful",
                    "ResponseCode" => 404,
                    "ResponseMessage" => 'Bank codes file not found.',
                ]);
            }
            $response = file_get_contents($path);
            return response()->json([
                "ResponseStatus" => "successful",
                "ResponseCode" => 0,
                "ResponseMessage" => json_decode($response),
            ]);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Dispute Methods ───────────────────────────────────────────────

    public function reportDispute(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'appid' => 'required|string',
            'dispute_referenceid' => 'string|required',
            'referenceid' => 'string|required',
            'dispute_category' => 'required|string',
            'dispute_description' => 'required|string',
            'dispute_files' => 'nullable|mimes:jpeg,jpg,png,pdf,gif,bmp|max:1024',
        ]);

        if ($validator->fails()) {
            $this->logRequestError($request);
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => $validator->errors(),
            ], 400);
        }

        try {
            $this->logRequest($request);

            $transact = Transaction::where('transcode', $request['referenceid'])->first();
            if (is_null($transact)) {
                return response()->json([
                    "ResponseStatus" => "Unsuccessful: transaction referenceid does exist",
                    "ResponseCode" => 400,
                    "ResponseMessage" => [],
                ], 400);
            }

            $merchant = Customer::where('email', $transact->merchant_email)->first();
            $customer = Customer::where('email', $transact->customer_email)->first();

            $dispute = Dispute::create([
                'merchant_id' => $merchant?->id,
                'customer_id' => $customer?->id,
                'customer_email' => $customer?->email,
                'merchant_email' => $merchant?->email,
                'appid' => $request->input('appid'),
                'transcode' => $transact->transcode,
                'dispute_referenceid' => $request->input('dispute_referenceid'),
                'dispute_category' => $request->input('dispute_category'),
                'dispute_description' => $request->input('dispute_description'),
            ]);

            if ($request->hasFile('dispute_files')) {
                $linkArray = $this->imageUtil->saveDocument(
                    $request->file('dispute_files'),
                    '/dispute_files/',
                    $dispute->id
                );

                if (!is_null($linkArray)) {
                    foreach ($linkArray as $link) {
                        DisputeFile::create([
                            'dispute_id' => $dispute->id,
                            'file_link' => $link,
                        ]);
                    }
                }
            }

            $dispute = new DisputeResource($dispute);
            return response()->json(compact('dispute'), 201);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    public function updateDispute(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'appid' => 'required|string',
            'dispute_referenceid' => 'string|required',
            'referenceid' => 'string|required',
            'dispute_category' => 'nullable|string',
            'dispute_description' => 'nullable|string',
            'arbitrator_name' => 'nullable|string',
            'arbitrator_profile' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            $this->logRequestError($request);
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => $validator->errors(),
            ], 400);
        }

        try {
            $this->logRequest($request);

            $dispute = Dispute::where([
                ['dispute_referenceid', $request->input('dispute_referenceid')],
                ['transcode', $request->input('referenceid')],
            ])->first();

            if (is_null($dispute)) {
                return response()->json([
                    "ResponseStatus" => "Unsuccessful: Dispute not found.",
                    "ResponseCode" => 400,
                    "ResponseMessage" => [],
                ], 400);
            }

            $dispute->update([
                'dispute_category' => $request->filled('dispute_category') ? $request->input('dispute_category') : $dispute->dispute_category,
                'dispute_description' => $request->filled('dispute_description') ? $request->input('dispute_description') : $dispute->dispute_description,
                'arbitrator_name' => $request->filled('arbitrator_name') ? $request->input('arbitrator_name') : $dispute->arbitrator_name,
                'arbitrator_profile' => $request->filled('arbitrator_profile') ? $request->input('arbitrator_profile') : $dispute->arbitrator_profile,
            ]);

            $dispute = new DisputeResource($dispute);
            return response()->json(compact('dispute'), 201);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    public function resolveDispute(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'appid' => 'required|string',
            'dispute_referenceid' => 'string|required',
            'referenceid' => 'string|required',
            'final_resolution' => 'required|string',
            'resolution_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            $this->logRequestError($request);
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => $validator->errors(),
            ], 400);
        }

        try {
            $this->logRequest($request);

            $dispute = Dispute::where([
                ['dispute_referenceid', $request->input('dispute_referenceid')],
                ['transcode', $request->input('referenceid')],
            ])->first();

            if (is_null($dispute)) {
                return response()->json([
                    "ResponseStatus" => "Unsuccessful: Dispute not found.",
                    "ResponseCode" => 400,
                    "ResponseMessage" => [],
                ], 400);
            }

            $dispute->update([
                'final_resolution' => $request->input('final_resolution'),
                'resolution_date' => $request->input('resolution_date'),
            ]);

            $dispute = new DisputeResource($dispute);
            return response()->json(compact('dispute'), 201);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    public function getDispute(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'appid' => 'required|string',
            'dispute_referenceid' => 'string|required',
            'referenceid' => 'string|required',
        ]);

        if ($validator->fails()) {
            $this->logRequestError($request);
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => $validator->errors(),
            ], 400);
        }

        try {
            $this->logRequest($request);

            $dispute = Dispute::where([
                ['dispute_referenceid', $request->input('dispute_referenceid')],
                ['transcode', $request->input('referenceid')],
            ])->first();

            if (is_null($dispute)) {
                return response()->json([
                    "ResponseStatus" => "Unsuccessful: Dispute not found.",
                    "ResponseCode" => 400,
                    "ResponseMessage" => [],
                ], 400);
            }

            $dispute = new DisputeResource($dispute);
            return response()->json(compact('dispute'), 201);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    public function getAllDispute(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'appid' => 'required|string',
        ]);

        if ($validator->fails()) {
            $this->logRequestError($request);
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => $validator->errors(),
            ], 400);
        }

        try {
            $this->logRequest($request);

            $disputes = Dispute::where('appid', $request->input('appid'))->get();
            $disputes = DisputeResource::collection($disputes);

            return response()->json(compact('disputes'), 201);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }

    public function reportDisputeHearing(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'appid' => 'required|string',
            'dispute_referenceid' => 'string|required',
            'referenceid' => 'string|required',
            'customer_comment' => 'nullable|string',
            'merchant_comment' => 'nullable|string',
            'arbitrator_comment' => 'nullable|string',
            'resolution_desc' => 'required|string',
            'sitting_date' => 'required|date',
            'next_sitting_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            $this->logRequestError($request);
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 400,
                "ResponseMessage" => $validator->errors(),
            ], 400);
        }

        try {
            $this->logRequest($request);

            $dispute = Dispute::where([
                ['dispute_referenceid', $request->input('dispute_referenceid')],
                ['transcode', $request->input('referenceid')],
            ])->first();

            if (is_null($dispute)) {
                return response()->json([
                    "ResponseStatus" => "Unsuccessful: Dispute not found.",
                    "ResponseCode" => 400,
                    "ResponseMessage" => [],
                ], 400);
            }

            DisputeResolution::create([
                'dispute_id' => $dispute->id,
                'transcode' => $dispute->transcode,
                'merchant_comment' => $request->input('merchant_comment'),
                'customer_comment' => $request->input('customer_comment'),
                'arbitrator_comment' => $request->input('arbitrator_comment'),
                'resolution_desc' => $request->input('resolution_desc'),
                'sitting_date' => $request->input('sitting_date'),
                'next_sitting_date' => $request->input('next_sitting_date'),
            ]);

            $dispute = new DisputeResource($dispute);
            return response()->json(compact('dispute'), 201);
        } catch (Exception $e) {
            return response()->json([
                "ResponseStatus" => "Unsuccessful",
                "ResponseCode" => 500,
                "ResponseMessage" => $e->getMessage(),
            ], 500);
        }
    }
}
