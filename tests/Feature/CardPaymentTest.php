<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\PaymentGateways\Contracts\PaymentGatewayInterface;
use App\Services\EscrowPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardPaymentTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;
    private Merchant $merchant;
    private Customer $customer;
    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->apiKey = 'ESCROW_TEST_KEY_' . bin2hex(random_bytes(16));

        ApiToken::factory()->create([
            'app_id' => $this->merchant->id,
            'merchant_id' => $this->merchant->id,
            'api_key' => $this->apiKey,
            'payment_gateway' => 'fake',
        ]);

        Customer::create([
            'name' => $this->merchant->business_name,
            'email' => $this->merchant->email,
            'usertype' => 'merchant',
            'merchantid' => (string) $this->merchant->id,
        ]);

        $this->customer = Customer::factory()->create();

        TransactionType::factory()->create([
            'merchant_id' => $this->merchant->id,
            'slug' => 'escrow',
        ]);

        $this->gateway = new FakeGateway();

        $service = app(EscrowPaymentService::class);
        $service->registerGateway('fake', $this->gateway);
        // The webhook handler resolves the reference per known gateway name, so
        // register the same instance under one of those for the webhook tests.
        $service->registerGateway('paystack', $this->gateway);
        config(['escrow.default_gateway' => 'fake']);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    private function cardPayload(string $reference, string $amount = '150000'): array
    {
        return [
            'appid' => (string) $this->merchant->id,
            'referenceid' => $reference,
            'user_email' => 'platform@example.com',
            'amount' => $amount,
            'country' => 'NG',
            'currency' => 'NGN',
            'customer_email' => $this->customer->email,
            'merchant_email' => $this->merchant->email,
            'description' => 'Escrow with card payment',
            'merchant_account_number' => '0123456789',
            'merchant_bank_code' => '058',
            'customer_name' => 'John Doe',
            'merchant_name' => 'Jane Smith',
            'peppfees' => '2500',
            'startdate' => '2026-07-01',
            'enddate' => '2026-07-30',
            'callback_url' => 'https://yourapp.com/callback',
            'transaction_type' => 'escrow',
        ];
    }

    public function test_create_card_returns_payment_url_and_creates_one_transaction(): void
    {
        $reference = 'REF-CARD-' . bin2hex(random_bytes(6));

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/escrow/Transaction/create_card', $this->cardPayload($reference));

        $response->assertOk();
        $response->assertJsonPath('ResponseCode', 0);

        $paymentUrl = $response->json('ResponseMessage.payment_url');
        $this->assertNotNull($paymentUrl, 'create_card must return a payment_url');
        $this->assertStringContainsString($reference, $paymentUrl);

        // Regression: the payment service used to create a second Transaction row
        // from the one the controller had already persisted.
        $this->assertSame(1, Transaction::where('transcode', $reference)->count());

        $this->assertDatabaseHas('transactions', [
            'transcode' => $reference,
            'payment_gateway' => 'fake',
            'trans_status' => 'PaymentPending',
        ]);
    }

    public function test_get_payment_link_does_not_duplicate_the_transaction(): void
    {
        $reference = 'REF-CARD-' . bin2hex(random_bytes(6));

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/escrow/Transaction/create_card', $this->cardPayload($reference))
            ->assertOk();

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/escrow/Transaction/getPaymentLink?' . http_build_query([
                'appid' => (string) $this->merchant->id,
                'referenceid' => $reference,
                'callback_url' => 'https://yourapp.com/callback',
            ]));

        $response->assertOk();
        $response->assertJsonPath('ResponseCode', 0);
        $this->assertNotNull($response->json('ResponseMessage.payment_url'));
        $this->assertSame(1, Transaction::where('transcode', $reference)->count());
    }

    public function test_verify_marks_transaction_paid_and_opens_escrow(): void
    {
        $reference = 'REF-CARD-' . bin2hex(random_bytes(6));

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/escrow/Transaction/create_card', $this->cardPayload($reference))
            ->assertOk();

        $this->gateway->paidAmount = 150000.0;

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/escrow/Transaction/verify?' . http_build_query([
                'appid' => (string) $this->merchant->id,
                'referenceid' => $reference,
            ]));

        $response->assertOk();
        $response->assertJsonPath('ResponseMessage.payment_verification_status', 'success');

        $this->assertDatabaseHas('transactions', [
            'transcode' => $reference,
            'payment_status' => 'Paid',
            'trans_status' => 'Open',
        ]);
    }

    public function test_verify_rejects_underpayment(): void
    {
        $reference = 'REF-CARD-' . bin2hex(random_bytes(6));

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/escrow/Transaction/create_card', $this->cardPayload($reference))
            ->assertOk();

        // Buyer pays less than the escrow amount.
        $this->gateway->paidAmount = 100.0;

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/escrow/Transaction/verify?' . http_build_query([
                'appid' => (string) $this->merchant->id,
                'referenceid' => $reference,
            ]))
            ->assertOk()
            ->assertJsonPath('ResponseMessage.payment_verification_status', 'amount_mismatch');

        $this->assertDatabaseMissing('transactions', [
            'transcode' => $reference,
            'trans_status' => 'Open',
        ]);
    }

    public function test_verify_is_idempotent(): void
    {
        $reference = 'REF-CARD-' . bin2hex(random_bytes(6));

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/escrow/Transaction/create_card', $this->cardPayload($reference))
            ->assertOk();

        $this->gateway->paidAmount = 150000.0;

        $query = http_build_query([
            'appid' => (string) $this->merchant->id,
            'referenceid' => $reference,
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/escrow/Transaction/verify?' . $query)->assertOk();

        $callsAfterFirst = $this->gateway->verifyCalls;

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/escrow/Transaction/verify?' . $query)
            ->assertOk()
            ->assertJsonPath('ResponseMessage.payment_verification_status', 'success');

        // Second verify must short-circuit rather than re-hit the gateway.
        $this->assertSame($callsAfterFirst, $this->gateway->verifyCalls);
    }

    public function test_webhook_is_reachable_without_an_api_key(): void
    {
        $reference = 'REF-CARD-' . bin2hex(random_bytes(6));

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/escrow/Transaction/create_card', $this->cardPayload($reference))
            ->assertOk();

        $this->gateway->paidAmount = 150000.0;

        // No Authorization header — gateways cannot send one.
        $response = $this->postJson('/api/escrow/webhook/paystack', [
            'data' => ['reference' => $reference],
        ]);

        $this->assertNotSame(401, $response->status(), 'gateway webhook must not require an API key');
    }

    public function test_webhook_rejects_an_unsigned_payload(): void
    {
        $reference = 'REF-CARD-' . bin2hex(random_bytes(6));

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/escrow/Transaction/create_card', $this->cardPayload($reference))
            ->assertOk();

        $this->gateway->signatureValid = false;

        $this->postJson('/api/escrow/webhook/paystack', [
            'data' => ['reference' => $reference],
        ])->assertStatus(401);

        $this->assertDatabaseMissing('transactions', [
            'transcode' => $reference,
            'payment_status' => 'Paid',
        ]);
    }
}

/**
 * In-memory gateway so the card flow can be exercised without network calls.
 */
class FakeGateway implements PaymentGatewayInterface
{
    public float $paidAmount = 0.0;
    public bool $signatureValid = true;
    public int $verifyCalls = 0;

    public function createPaymentLink(
        float $amount,
        string $currency,
        string $reference,
        string $customerEmail,
        string $customerName,
        string $callbackUrl,
        array $metadata = []
    ): array {
        return [
            'paymentUrl' => 'https://fake-gateway.test/pay/' . $reference,
            'reference' => $reference,
        ];
    }

    public function verifyPayment(string $reference): array
    {
        $this->verifyCalls++;

        return [
            'status' => 'success',
            'amount' => $this->paidAmount,
            'currency' => 'NGN',
            'reference' => $reference,
            'gateway_response' => ['reference' => $reference],
        ];
    }

    public function processRefund(string $transactionReference, float $amount, string $reason = ''): array
    {
        return [
            'status' => 'succeeded',
            'reference' => $transactionReference,
            'message' => 'Refunded',
        ];
    }

    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        return $this->signatureValid;
    }

    public function getName(): string
    {
        return 'Fake Gateway';
    }

    public function getKey(): string
    {
        return 'fake';
    }
}
