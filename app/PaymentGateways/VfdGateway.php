<?php

namespace App\PaymentGateways;

use App\PaymentGateways\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VFD Microfinance Bank (VBaaS Wallets API).
 *
 * VFD is a banking rail, not a hosted-checkout gateway: it provides virtual
 * accounts, name enquiry and bank transfers. There is no card checkout, so
 * createPaymentLink() and processRefund() are unsupported by design — pair VFD
 * with a card gateway (Paystack) when a merchant needs both.
 */
class VfdGateway implements PaymentGatewayInterface
{
    /** VFD's own bank code — used to decide intra vs inter transfers. */
    public const VFD_BANK_CODE = '999999';

    protected string $consumerKey;
    protected string $consumerSecret;
    protected string $baseUrl;
    protected string $authUrl;
    protected string $poolAccount;
    protected int $validityTime;
    protected string $webhookSecret;
    protected array $webhookIps;

    public function __construct(array $config)
    {
        $this->consumerKey = $config['consumer_key'] ?? '';
        $this->consumerSecret = $config['consumer_secret'] ?? '';
        $this->baseUrl = rtrim($config['base_url'] ?? '', '/');
        $this->authUrl = rtrim($config['auth_url'] ?? '', '/');
        $this->poolAccount = $config['pool_account'] ?? '';
        $this->validityTime = (int) ($config['validity_time'] ?? 60);
        $this->webhookSecret = $config['webhook_secret'] ?? '';
        $this->webhookIps = array_filter(array_map('trim', explode(',', $config['webhook_ips'] ?? '')));
    }

    public function getName(): string
    {
        return 'VFD Microfinance Bank';
    }

    public function getKey(): string
    {
        return 'vfd';
    }

    // ─── Authentication ───────────────────────────────────────────────

    /**
     * Fetch (and cache) a BaaS access token. Cached slightly short of its real
     * validity so an in-flight request never races the expiry.
     */
    public function getAccessToken(bool $forceRefresh = false): string
    {
        $cacheKey = 'vfd.access_token.' . md5($this->consumerKey);

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, $this->tokenCacheSeconds(), function () {
            $response = Http::asJson()->post("{$this->authUrl}/baasauth/token", [
                'consumerKey' => $this->consumerKey,
                'consumerSecret' => $this->consumerSecret,
                'validityTime' => (string) $this->validityTime,
            ]);

            $result = $response->json();
            $token = $result['data']['access_token'] ?? $result['access_token'] ?? null;

            if (!$response->successful() || !$token) {
                Log::error('VFD auth error', ['response' => $result ?? []]);
                throw new \Exception($result['message'] ?? 'Failed to obtain VFD access token');
            }

            return $token;
        });
    }

    protected function tokenCacheSeconds(): int
    {
        // -1 means a non-expiring token; still re-fetch daily so a revoked key
        // does not leave us wedged on a stale token.
        if ($this->validityTime < 0) {
            return 86400;
        }

        return max(60, ($this->validityTime * 60) - 60);
    }

    /**
     * VFD authenticates with an `AccessToken` header, not `Authorization`.
     */
    protected function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'AccessToken' => $this->getAccessToken(),
            'Accept' => 'application/json',
        ])->asJson();
    }

    /**
     * Unwrap a VFD envelope. Every endpoint returns status "00" on success.
     */
    protected function unwrap(\Illuminate\Http\Client\Response $response, string $context): array
    {
        $result = $response->json() ?? [];

        if (!$response->successful() || ($result['status'] ?? null) !== '00') {
            Log::error("VFD {$context} error", ['response' => $result]);
            throw new \Exception($result['message'] ?? "VFD {$context} failed");
        }

        return $result['data'] ?? [];
    }

    // ─── PaymentGatewayInterface ──────────────────────────────────────

    public function createPaymentLink(
        float $amount,
        string $currency,
        string $reference,
        string $customerEmail,
        string $customerName,
        string $callbackUrl,
        array $metadata = []
    ): array {
        throw new \BadMethodCallException(
            'VFD does not provide hosted checkout. Use a virtual account for collection, '
            . 'or route card payments to another gateway.'
        );
    }

    public function processRefund(string $transactionReference, float $amount, string $reason = ''): array
    {
        throw new \BadMethodCallException(
            'VFD has no refund API. Refund by transferring back to the payer account.'
        );
    }

    public function verifyPayment(string $reference): array
    {
        $data = $this->unwrap(
            $this->client()->get("{$this->baseUrl}/transactions", ['reference' => $reference]),
            'transaction query'
        );

        return [
            'status' => $this->mapTransactionStatus($data['transactionStatus'] ?? null),
            'amount' => (float) ($data['amount'] ?? 0),
            'currency' => 'NGN',
            'reference' => $data['TxnId'] ?? $reference,
            'gateway_response' => $data,
        ];
    }

    /**
     * VFD's transaction status codes: "00" settled, "99" failed, anything else
     * is still in flight.
     */
    protected function mapTransactionStatus(?string $code): string
    {
        return match ($code) {
            '00' => 'success',
            '99' => 'failed',
            default => 'pending',
        };
    }

    /**
     * VFD does not sign its webhooks. Authenticity is established by a shared
     * secret we hand them for the callback URL, optionally narrowed to their
     * source IPs. Without a configured secret this returns false rather than
     * waving traffic through — an unauthenticated credit notification would let
     * anyone mark an escrow transaction as funded.
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        if ($this->webhookSecret === '') {
            Log::warning('VFD webhook rejected: no webhook secret configured');
            return false;
        }

        $presented = $headers['x-vfd-signature'] ?? $headers['accesstoken'] ?? '';

        if (is_array($presented)) {
            $presented = $presented[0] ?? '';
        }

        if ($presented === '' || !hash_equals($this->webhookSecret, (string) $presented)) {
            return false;
        }

        if (!empty($this->webhookIps)) {
            $ip = $headers['x-forwarded-for'] ?? request()->ip();
            if (is_array($ip)) {
                $ip = $ip[0] ?? '';
            }
            $ip = trim(explode(',', (string) $ip)[0]);

            if (!in_array($ip, $this->webhookIps, true)) {
                Log::warning('VFD webhook rejected: source IP not allow-listed', ['ip' => $ip]);
                return false;
            }
        }

        return true;
    }

    // ─── Virtual accounts ─────────────────────────────────────────────

    /**
     * Create a one-time virtual account bound to an exact amount. VFD expires
     * these itself once paid, so they are never reused across transactions.
     */
    public function createTemporaryVirtualAccount(
        float $amount,
        string $customerEmail,
        ?string $phone = null,
    ): array {
        $data = $this->unwrap(
            $this->client()->post("{$this->baseUrl}/virtualaccount", [
                'accountNo' => $this->poolAccount,
                'amount' => $this->formatAmount($amount),
                'phone' => $phone ?? '',
                'email' => $customerEmail,
            ]),
            'virtual account creation'
        );

        $accountNumber = $data['accountNumber'] ?? null;

        if (!$accountNumber) {
            throw new \Exception('VFD did not return a virtual account number');
        }

        return [
            'account_number' => $accountNumber,
            'account_name' => $data['accountName'] ?? 'VFD Escrow Collection',
            'bank_name' => 'VFD Microfinance Bank',
            'provider_reference' => (string) $accountNumber,
        ];
    }

    /**
     * Re-price an existing temporary account (e.g. after a transaction amount
     * is extended) without issuing a new account number.
     */
    public function updateVirtualAccountAmount(string $accountNumber, float $amount): array
    {
        $data = $this->unwrap(
            $this->client()->post("{$this->baseUrl}/virtualaccount/amountupdate", [
                'accountNo' => $accountNumber,
                'amount' => $this->formatAmount($amount),
            ]),
            'virtual account amount update'
        );

        return [
            'status' => 'updated',
            'account_number' => $data['accountNumber'] ?? $accountNumber,
        ];
    }

    /**
     * VFD temporary accounts lapse on their own; there is no deactivate call.
     * Kept so callers can treat every gateway uniformly.
     */
    public function deactivateVirtualAccount(string $providerReference): array
    {
        return [
            'status' => 'deactivated',
            'message' => 'VFD temporary accounts expire automatically; nothing to revoke.',
        ];
    }

    // ─── Banks and name enquiry ───────────────────────────────────────

    /**
     * @return array<int, array{code: string, name: string}>
     */
    public function getBanks(): array
    {
        $data = $this->unwrap(
            $this->client()->get("{$this->baseUrl}/bank"),
            'bank list'
        );

        return array_map(fn ($bank) => [
            'code' => (string) ($bank['code'] ?? ''),
            'name' => $bank['name'] ?? '',
        ], $data['bank'] ?? $data);
    }

    /**
     * Resolve an account number to its owner. Returns the full enquiry payload
     * because initiateTransfer() needs the client/savings ids it carries.
     */
    public function nameEnquiry(string $accountNumber, string $bankCode): array
    {
        $transferType = $bankCode === self::VFD_BANK_CODE ? 'intra' : 'inter';

        $data = $this->unwrap(
            $this->client()->get("{$this->baseUrl}/transfer/recipient", [
                'accountNo' => $accountNumber,
                'bank' => $bankCode,
                'transfer_type' => $transferType,
            ]),
            'name enquiry'
        );

        return [
            'account_number' => $data['account']['number'] ?? $accountNumber,
            'account_name' => $data['name'] ?? '',
            'bank_code' => $bankCode,
            'bank_name' => $data['bank'] ?? '',
            'client_id' => $data['clientId'] ?? '',
            'savings_id' => $data['account']['id'] ?? '',
            'bvn' => $data['bvn'] ?? '',
            'session_id' => $data['sessionId'] ?? '',
            'transfer_type' => $transferType,
        ];
    }

    /**
     * Details of our own pool account, needed as the debit side of a transfer.
     */
    public function accountEnquiry(?string $accountNumber = null): array
    {
        $query = $accountNumber ? ['accountNumber' => $accountNumber] : [];

        $data = $this->unwrap(
            $this->client()->get("{$this->baseUrl}/account/enquiry", $query),
            'account enquiry'
        );

        return [
            'account_number' => $data['accountNo'] ?? ($accountNumber ?? $this->poolAccount),
            'account_name' => $data['accountName'] ?? '',
            'account_balance' => (float) ($data['accountBalance'] ?? 0),
            'client_id' => $data['clientId'] ?? '',
            'savings_id' => $data['accountId'] ?? '',
            'bvn' => $data['bvn'] ?? '',
        ];
    }

    public function checkBalance(): array
    {
        $pool = $this->accountEnquiry();

        return ['NGN' => ['balance' => $pool['account_balance'], 'limit' => 0.0]];
    }

    // ─── Transfers / payouts ──────────────────────────────────────────

    /**
     * Move funds from the pool account to a beneficiary.
     *
     * Unlike Paystack there is no stored recipient: every transfer carries the
     * full debit and credit sides, so we run both enquiries first and derive
     * the SHA512(fromAccount + toAccount) signature VFD requires.
     *
     * @param array{account_number: string, bank_code: string} $bankAccount
     */
    public function initiateTransfer(
        array $bankAccount,
        float $amount,
        string $reference,
        string $remark = '',
    ): array {
        $beneficiary = $this->nameEnquiry($bankAccount['account_number'], $bankAccount['bank_code']);
        $source = $this->accountEnquiry();

        $payload = [
            'fromAccount' => $source['account_number'],
            'uniqueSenderAccountId' => '',
            'fromClientId' => $source['client_id'],
            'fromClient' => $source['account_name'],
            'fromSavingsId' => $source['savings_id'],
            'fromBvn' => $source['bvn'],
            'toClientId' => $beneficiary['client_id'],
            'toClient' => $beneficiary['account_name'],
            'toSavingsId' => $beneficiary['savings_id'],
            'toSession' => $beneficiary['session_id'],
            'toBvn' => $beneficiary['bvn'],
            'toAccount' => $beneficiary['account_number'],
            'toBank' => $bankAccount['bank_code'],
            'signature' => $this->transferSignature($source['account_number'], $beneficiary['account_number']),
            'amount' => $this->formatAmount($amount),
            'remark' => $remark,
            'transferType' => $beneficiary['transfer_type'],
            'reference' => $reference,
        ];

        $data = $this->unwrap(
            $this->client()->post("{$this->baseUrl}/transfer", $payload),
            'transfer'
        );

        return [
            'status' => 'pending',
            'transfer_code' => $data['txnId'] ?? $reference,
            'reference' => $data['reference'] ?? $reference,
            'session_id' => $data['sessionId'] ?? null,
            'amount' => $amount,
            'account_name' => $beneficiary['account_name'],
        ];
    }

    public function verifyTransfer(string $transferReference): array
    {
        return $this->verifyPayment($transferReference);
    }

    protected function transferSignature(string $fromAccount, string $toAccount): string
    {
        return hash('sha512', $fromAccount . $toAccount);
    }

    /**
     * VFD takes amounts in naira as a decimal string, not in kobo.
     */
    protected function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
