<?php

namespace App\PaymentGateways;

use App\PaymentGateways\Contracts\PaymentGatewayInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Modulr (UK/EEA e-money institution).
 *
 * Like VFD, Modulr is a banking rail rather than a hosted checkout: it issues
 * accounts with their own sort code + account number or IBAN, reports money
 * arriving in them, and pays out to bank accounts. createPaymentLink() and
 * processRefund() are unsupported by design. A refund is a payout back to the
 * payer's account.
 */
class ModulrGateway implements PaymentGatewayInterface
{
    protected string $apiKey;
    protected string $apiSecret;
    protected string $baseUrl;
    protected string $customerId;
    protected array $productCodes;
    protected string $webhookId;
    protected string $webhookSecret;

    public function __construct(array $config)
    {
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->apiSecret = (string) ($config['api_secret'] ?? '');
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->customerId = (string) ($config['customer_id'] ?? '');
        $this->productCodes = array_filter((array) ($config['product_codes'] ?? []));
        $this->webhookId = (string) ($config['webhook_id'] ?? '');
        $this->webhookSecret = (string) ($config['webhook_secret'] ?? '');
    }

    public function getName(): string
    {
        return 'Modulr';
    }

    public function getKey(): string
    {
        return 'modulr';
    }

    // ─── Authentication ───────────────────────────────────────────────

    /**
     * Modulr's HMAC scheme signs two headers, not the body: the request Date
     * and a nonce that must be unique per request. The signature is the
     * base64 of the raw HMAC bytes, URL-encoded.
     */
    public static function sign(string $keyId, string $secret, string $date, string $nonce, string $algorithm = 'hmac-sha1'): string
    {
        $raw = hash_hmac(self::phpAlgorithm($algorithm), "date: {$date}\nx-mod-nonce: {$nonce}", $secret, true);

        return sprintf(
            'Signature keyId="%s",algorithm="%s",headers="date x-mod-nonce",signature="%s"',
            $keyId,
            $algorithm,
            rawurlencode(base64_encode($raw)),
        );
    }

    protected static function phpAlgorithm(string $algorithm): string
    {
        return match (strtolower($algorithm)) {
            'hmac-sha256' => 'sha256',
            'hmac-sha384' => 'sha384',
            'hmac-sha512' => 'sha512',
            default => 'sha1',
        };
    }

    /** RFC 7231 HTTP-date in GMT, as Modulr expects in the Date header. */
    protected function httpDate(): string
    {
        return gmdate('D, d M Y H:i:s') . ' GMT';
    }

    /**
     * A fresh date and nonce per request: a replayed nonce is rejected by
     * Modulr, so the client must be rebuilt for every call.
     */
    protected function client(): PendingRequest
    {
        $date = $this->httpDate();
        $nonce = (string) Str::uuid();

        return Http::withHeaders([
            'Date' => $date,
            'x-mod-nonce' => $nonce,
            'Authorization' => self::sign($this->apiKey, $this->apiSecret, $date, $nonce),
            'Accept' => 'application/json',
        ])->asJson();
    }

    protected function unwrap(Response $response, string $context): array
    {
        $result = $response->json() ?? [];

        if (!$response->successful()) {
            Log::error("Modulr {$context} error", ['status' => $response->status(), 'response' => $result]);

            // Validation errors arrive as a list of {field, code, message}.
            $message = is_array($result) && isset($result[0]['message'])
                ? $result[0]['message']
                : ($result['message'] ?? "Modulr {$context} failed");

            throw new \Exception($message);
        }

        return is_array($result) ? $result : [];
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
            'Modulr does not provide hosted checkout. Issue an account for the payment '
            . 'and give the payer its bank details instead.'
        );
    }

    public function processRefund(string $transactionReference, float $amount, string $reason = ''): array
    {
        throw new \BadMethodCallException(
            'Modulr has no refund API. Refund by paying back to the payer\'s account.'
        );
    }

    /** Looks up a payment by Modulr's payment id. */
    public function verifyPayment(string $reference): array
    {
        $data = $this->unwrap($this->client()->get("{$this->baseUrl}/payments", ['id' => $reference]), 'payment query');
        $payment = $data['content'][0] ?? $data;

        return [
            'status' => $this->mapPaymentStatus($payment['status'] ?? null),
            'amount' => (float) ($payment['details']['amount'] ?? $payment['amount'] ?? 0),
            'currency' => $payment['details']['currency'] ?? $payment['currency'] ?? null,
            'reference' => $payment['id'] ?? $reference,
            'gateway_response' => $payment,
        ];
    }

    protected function mapPaymentStatus(?string $status): string
    {
        return match ($status) {
            'PROCESSED', 'RECONCILED' => 'success',
            'ER_INVALID', 'ER_EXTCONN', 'ER_EXTSYS', 'ER_EXPIRED', 'ER_GENERAL', 'ER_BATCH',
            'RETURNED', 'CANCELLED', 'VOID' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Modulr signs webhooks with the same scheme as API requests, keyed by
     * the webhook id and the secret we gave when subscribing.
     *
     * TO CONFIRM against the sandbox: Modulr's docs do not name the header
     * that carries the signature, or say whether the body is covered. This
     * checks the Authorization header over Date + x-mod-nonce, as the request
     * scheme does. Because that would not cover the body, a pay-in should
     * still be confirmed against the API before a hold is marked paid.
     *
     * Without a configured secret this returns false: an unauthenticated
     * pay-in notification would let anyone mark a hold as funded.
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        if ($this->webhookSecret === '' || $this->webhookId === '') {
            Log::warning('Modulr webhook rejected: no webhook id or secret configured');
            return false;
        }

        $header = fn (string $name) => is_array($headers[$name] ?? null)
            ? (string) ($headers[$name][0] ?? '')
            : (string) ($headers[$name] ?? '');

        $presented = $header('authorization');
        $date = $header('date');
        $nonce = $header('x-mod-nonce');

        if ($presented === '' || $date === '' || $nonce === '') {
            return false;
        }

        if (!preg_match('/algorithm="([^"]+)"/', $presented, $m)) {
            return false;
        }

        $expected = self::sign($this->webhookId, $this->webhookSecret, $date, $nonce, $m[1]);

        return hash_equals($expected, $presented);
    }

    // ─── Accounts ─────────────────────────────────────────────────────

    /**
     * Issue a new account under our customer. One per hold, tagged with the
     * hold's reference, so any money arriving in it belongs to that hold.
     *
     * @return array{account_id: string, status: string, sort_code: ?string, account_number: ?string, iban: ?string, bic: ?string, currency: string, raw: array}
     */
    public function createAccount(string $currency, string $externalReference): array
    {
        $currency = strtoupper($currency);
        $productCode = $this->productCodes[$currency] ?? null;

        if (!$productCode) {
            throw new \InvalidArgumentException("No Modulr product code is configured for {$currency}.");
        }

        $data = $this->unwrap(
            $this->client()->post("{$this->baseUrl}/customers/{$this->customerId}/accounts", [
                'currency' => $currency,
                'productCode' => $productCode,
                'externalReference' => self::externalReference($externalReference),
            ]),
            'account creation'
        );

        return $this->presentAccount($data, $currency);
    }

    public function getAccount(string $accountId): array
    {
        $data = $this->unwrap($this->client()->get("{$this->baseUrl}/accounts/{$accountId}"), 'account query');

        return $this->presentAccount($data, $data['currency'] ?? '');
    }

    protected function presentAccount(array $data, string $currency): array
    {
        $identifiers = $data['identifiers'] ?? [];
        $pick = function (string $field) use ($identifiers) {
            foreach ($identifiers as $identifier) {
                if (!empty($identifier[$field])) {
                    return $identifier[$field];
                }
            }

            return null;
        };

        return [
            'account_id' => (string) ($data['id'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
            'balance' => isset($data['balance']) ? (float) $data['balance'] : null,
            'sort_code' => $pick('sortCode'),
            'account_number' => $pick('accountNumber'),
            'iban' => $pick('iban'),
            'bic' => $pick('bic'),
            'currency' => $data['currency'] ?? $currency,
            'raw' => $data,
        ];
    }

    /**
     * Modulr allows up to 50 characters of letters, digits, `_`, `-` and
     * spaces. Hold references allow more (`.` and `:`), so anything else is
     * replaced rather than rejected.
     */
    public static function externalReference(string $reference): string
    {
        return substr(preg_replace('/[^\w\- ]/', '-', $reference), 0, 50);
    }

    // ─── Payments ─────────────────────────────────────────────────────

    /**
     * Pay out from one of our accounts to a bank account.
     *
     * $payee is either ['name', 'sort_code', 'account_number'] for a UK
     * account or ['name', 'iban'] for an IBAN. $externalReference is our
     * idempotency handle on the payment, so a retry carries the same one.
     */
    public function payOut(
        string $sourceAccountId,
        array $payee,
        float $amount,
        string $currency,
        string $reference,
        string $externalReference
    ): array {
        return $this->sendPayment($sourceAccountId, $this->destination($payee), $amount, $currency, $reference, $externalReference);
    }

    /** Move money between two of our own accounts. Booked immediately. */
    public function transferBetweenAccounts(
        string $sourceAccountId,
        string $destinationAccountId,
        float $amount,
        string $currency,
        string $reference,
        string $externalReference
    ): array {
        return $this->sendPayment(
            $sourceAccountId,
            ['type' => 'ACCOUNT', 'id' => $destinationAccountId],
            $amount,
            $currency,
            $reference,
            $externalReference,
        );
    }

    protected function destination(array $payee): array
    {
        $name = trim((string) ($payee['name'] ?? ''));

        if ($name === '') {
            throw new \InvalidArgumentException('A payee name is required.');
        }

        if (!empty($payee['iban'])) {
            return ['type' => 'IBAN', 'name' => $name, 'iban' => strtoupper(str_replace(' ', '', $payee['iban']))];
        }

        $sortCode = preg_replace('/\D/', '', (string) ($payee['sort_code'] ?? ''));
        $accountNumber = preg_replace('/\D/', '', (string) ($payee['account_number'] ?? ''));

        if (strlen($sortCode) !== 6 || strlen($accountNumber) !== 8) {
            throw new \InvalidArgumentException('A UK payee needs a 6-digit sort code and an 8-digit account number, or an IBAN.');
        }

        return ['type' => 'SCAN', 'name' => $name, 'sortCode' => $sortCode, 'accountNumber' => $accountNumber];
    }

    protected function sendPayment(
        string $sourceAccountId,
        array $destination,
        float $amount,
        string $currency,
        string $reference,
        string $externalReference
    ): array {
        $data = $this->unwrap(
            $this->client()->post("{$this->baseUrl}/payments", [
                'sourceAccountId' => $sourceAccountId,
                'destination' => $destination,
                'currency' => strtoupper($currency),
                'amount' => round($amount, 2),
                'reference' => self::paymentReference($reference, $currency),
                'externalReference' => self::externalReference($externalReference),
            ]),
            'payment'
        );

        // Payments are asynchronous: 201 means accepted, not paid.
        return [
            'payment_id' => (string) ($data['id'] ?? ''),
            'status' => $this->mapPaymentStatus($data['status'] ?? null),
            'modulr_status' => $data['status'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * The reference the payee sees on their statement. GBP allows 6 to 18
     * characters, other currencies up to 140, and it may not be one character
     * repeated.
     */
    public static function paymentReference(string $reference, string $currency): string
    {
        $max = strtoupper($currency) === 'GBP' ? 18 : 140;
        $clean = substr(trim(preg_replace('/[^A-Za-z0-9\-\. ]/', ' ', $reference)), 0, $max);

        if (strlen($clean) < 6 || count(array_unique(str_split($clean))) === 1) {
            $clean = substr('PEPPA ' . $clean, 0, $max);
        }

        return $clean;
    }

    // ─── Webhooks ─────────────────────────────────────────────────────

    /**
     * Normalise a PAYIN notification. PaymentId is the duplicate check: the
     * EventId can change when Modulr resends.
     *
     * `payer` holds the sender's bank details where Modulr reports them, in
     * the shape payOut() takes, so a refund can be sent back to them.
     *
     * @return array{payment_id: string, account_id: string, amount: float, currency: string, payer_name: ?string, payer: ?array, reference: ?string, type: ?string}|null
     */
    public static function parsePayin(array $payload): ?array
    {
        if (($payload['EventName'] ?? null) !== 'PAYIN' || empty($payload['PaymentId']) || empty($payload['AccountId'])) {
            return null;
        }

        $payerName = $payload['PayerName'] ?? ($payload['Payer']['Name'] ?? null);
        $identifier = $payload['Payer']['Identifier'] ?? [];

        $payer = array_filter([
            'name' => $payerName,
            'sort_code' => $identifier['SortCode'] ?? $identifier['sortCode'] ?? null,
            'account_number' => $identifier['AccountNumber'] ?? $identifier['accountNumber'] ?? null,
            'iban' => $identifier['Iban'] ?? $identifier['iban'] ?? null,
            'bic' => $identifier['Bic'] ?? $identifier['bic'] ?? null,
        ]);

        $payable = !empty($payer['name']) && (!empty($payer['iban']) || (!empty($payer['sort_code']) && !empty($payer['account_number'])));

        return [
            'payment_id' => (string) $payload['PaymentId'],
            'account_id' => (string) $payload['AccountId'],
            'amount' => (float) ($payload['Amount'] ?? 0),
            'currency' => strtoupper((string) ($payload['Currency'] ?? '')),
            'payer_name' => $payerName,
            'payer' => $payable ? $payer : null,
            'reference' => $payload['PaymentReference'] ?? null,
            'type' => $payload['Type'] ?? null,
        ];
    }
}
