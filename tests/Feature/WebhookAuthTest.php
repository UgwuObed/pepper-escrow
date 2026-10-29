<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\VirtualAccount;
use App\Services\EscrowPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The webhook routes carry no API key — anyone can post to them — so the
 * signature check is the only thing standing between a stranger and "this
 * escrow is funded". These routes went unverified until the VFD work.
 */
class WebhookAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PAYSTACK_SECRET = 'sk_test_webhook_auth';
    private const VFD_WEBHOOK_SECRET = 'vfd-shared-secret';

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();

        config([
            'gateways.paystack.secret_key' => self::PAYSTACK_SECRET,
            'gateways.paystack.public_key' => 'pk_test_webhook_auth',
            'gateways.vfd.consumer_key' => 'ck_test',
            'gateways.vfd.consumer_secret' => 'cs_test',
            'gateways.vfd.pool_account' => '1000000001',
            'gateways.vfd.webhook_secret' => self::VFD_WEBHOOK_SECRET,
            'gateways.vfd.webhook_ips' => '',
        ]);

        // The service is a singleton that reads config when it is first
        // resolved, so drop any instance built before the config above.
        $this->app->forgetInstance(EscrowPaymentService::class);
    }

    private function paystackSignature(array $payload): string
    {
        return hash_hmac('sha512', json_encode($payload), self::PAYSTACK_SECRET);
    }

    private function fundedTransaction(string $gateway, string $accountNumber): Transaction
    {
        $transaction = Transaction::factory()->create([
            'merchantid' => $this->merchant->id,
            'customer_email' => 'buyer@example.com',
            'amount' => 5000,
            'payment_status' => 'Unpaid',
            'trans_status' => 'PaymentPending',
        ]);

        VirtualAccount::create([
            'merchant_id' => $this->merchant->id,
            'transaction_id' => $transaction->id,
            'gateway' => $gateway,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'buyer@example.com',
            'account_number' => $accountNumber,
            'account_name' => 'Pepper Escrow',
            'bank_name' => 'Test Bank',
            'provider_reference' => $accountNumber,
            'status' => 'assigned',
            'is_permanent' => $gateway !== 'vfd',
        ]);

        return $transaction;
    }

    // ─── Bank transfer credit webhooks ────────────────────────────────

    public function test_unsigned_paystack_credit_webhook_is_rejected(): void
    {
        $transaction = $this->fundedTransaction('paystack', '0123456789');

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'amount' => 500000,
                'reference' => 'PSK-1',
                'customer' => ['email' => 'buyer@example.com'],
                'authorization' => ['account_number' => '0123456789'],
            ],
        ];

        $response = $this->postJson('api/bank-transfer/webhook/paystack', $payload);

        $response->assertStatus(401);

        $this->assertSame('Unpaid', $transaction->fresh()->payment_status);
    }

    public function test_paystack_credit_webhook_with_a_forged_signature_is_rejected(): void
    {
        $transaction = $this->fundedTransaction('paystack', '0123456789');

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'amount' => 500000,
                'reference' => 'PSK-1',
                'customer' => ['email' => 'buyer@example.com'],
                'authorization' => ['account_number' => '0123456789'],
            ],
        ];

        $response = $this->withHeaders(['x-paystack-signature' => str_repeat('a', 128)])
            ->postJson('api/bank-transfer/webhook/paystack', $payload);

        $response->assertStatus(401);

        $this->assertSame('Unpaid', $transaction->fresh()->payment_status);
    }

    public function test_correctly_signed_paystack_credit_webhook_funds_the_transaction(): void
    {
        $transaction = $this->fundedTransaction('paystack', '0123456789');

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'amount' => 500000,
                'reference' => 'PSK-1',
                'customer' => ['email' => 'buyer@example.com'],
                'authorization' => ['account_number' => '0123456789'],
            ],
        ];

        $response = $this->withHeaders(['x-paystack-signature' => $this->paystackSignature($payload)])
            ->postJson('api/bank-transfer/webhook/paystack', $payload);

        $response->assertStatus(200);

        $transaction->refresh();
        $this->assertSame('Paid', $transaction->payment_status);
        $this->assertSame('Open', $transaction->trans_status);
    }

    public function test_unsigned_vfd_credit_webhook_is_rejected(): void
    {
        $transaction = $this->fundedTransaction('vfd', '1001563612');

        $response = $this->postJson('api/bank-transfer/webhook/vfd', [
            'status' => '00',
            'data' => ['accountNo' => '1001563612', 'amount' => '5000.00', 'reference' => 'VFD-1'],
        ]);

        $response->assertStatus(401);

        $this->assertSame('Unpaid', $transaction->fresh()->payment_status);
    }

    public function test_vfd_credit_webhook_with_the_shared_secret_funds_the_transaction(): void
    {
        $transaction = $this->fundedTransaction('vfd', '1001563612');

        $response = $this->withHeaders(['x-vfd-signature' => self::VFD_WEBHOOK_SECRET])
            ->postJson('api/bank-transfer/webhook/vfd', [
                'status' => '00',
                'data' => ['accountNo' => '1001563612', 'amount' => '5000.00', 'reference' => 'VFD-1'],
            ]);

        $response->assertStatus(200);

        $transaction->refresh();
        $this->assertSame('Paid', $transaction->payment_status);
        $this->assertSame('vfd', $transaction->payment_gateway);

        // One-time account must not survive to take a second payment.
        $this->assertSame('closed', VirtualAccount::where('account_number', '1001563612')->first()->status);
    }

    public function test_vfd_credit_webhook_does_not_fund_an_underpaid_transaction(): void
    {
        $transaction = $this->fundedTransaction('vfd', '1001563612');

        $response = $this->withHeaders(['x-vfd-signature' => self::VFD_WEBHOOK_SECRET])
            ->postJson('api/bank-transfer/webhook/vfd', [
                'status' => '00',
                'data' => ['accountNo' => '1001563612', 'amount' => '100.00', 'reference' => 'VFD-1'],
            ]);

        $response->assertStatus(200);

        $transaction->refresh();
        $this->assertNotSame('Paid', $transaction->payment_status);
        $this->assertNotSame('Open', $transaction->trans_status);
    }

    // ─── Settlement transfer webhooks ─────────────────────────────────

    public function test_unsigned_settlement_webhook_is_rejected(): void
    {
        $settlement = Settlement::factory()->create([
            'merchant_id' => $this->merchant->id,
            'payment_gateway' => 'paystack',
            'status' => 'processing',
        ]);

        $response = $this->postJson('api/settlements/webhook/paystack', [
            'event' => 'transfer.success',
            'data' => ['reference' => $settlement->batch_number, 'status' => 'success'],
        ]);

        $response->assertStatus(401);

        $this->assertSame('processing', $settlement->fresh()->status);
    }

    public function test_correctly_signed_settlement_webhook_completes_the_batch(): void
    {
        $settlement = Settlement::factory()->create([
            'merchant_id' => $this->merchant->id,
            'payment_gateway' => 'paystack',
            'status' => 'processing',
        ]);

        $payload = [
            'event' => 'transfer.success',
            'data' => ['reference' => $settlement->batch_number, 'status' => 'success'],
        ];

        $response = $this->withHeaders(['x-paystack-signature' => $this->paystackSignature($payload)])
            ->postJson('api/settlements/webhook/paystack', $payload);

        $response->assertStatus(200);
        $this->assertSame('completed', $settlement->fresh()->status);
    }

    public function test_unsigned_vfd_settlement_webhook_is_rejected(): void
    {
        $settlement = Settlement::factory()->create([
            'merchant_id' => $this->merchant->id,
            'payment_gateway' => 'vfd',
            'status' => 'processing',
        ]);

        $response = $this->postJson('api/settlements/webhook/vfd', [
            'event' => 'payout.success',
            'data' => ['reference' => $settlement->batch_number],
        ]);

        $response->assertStatus(401);
        $this->assertSame('processing', $settlement->fresh()->status);
    }

    public function test_signed_vfd_settlement_webhook_completes_the_batch(): void
    {
        $settlement = Settlement::factory()->create([
            'merchant_id' => $this->merchant->id,
            'payment_gateway' => 'vfd',
            'status' => 'processing',
        ]);

        $response = $this->withHeaders(['x-vfd-signature' => self::VFD_WEBHOOK_SECRET])
            ->postJson('api/settlements/webhook/vfd', [
                'event' => 'payout.success',
                'data' => ['reference' => $settlement->batch_number],
            ]);

        $response->assertStatus(200);
        $this->assertSame('completed', $settlement->fresh()->status);
    }

    /**
     * A gateway with no credentials configured must fail closed rather than
     * waving the request through.
     */
    public function test_webhook_for_an_unconfigured_gateway_is_rejected(): void
    {
        config(['gateways.flutterwave.secret_key' => null]);
        $this->app->forgetInstance(EscrowPaymentService::class);

        $response = $this->postJson('api/bank-transfer/webhook/flutterwave', [
            'event' => 'charge.completed',
            'data' => ['account_number' => '0123456789'],
        ]);

        $response->assertStatus(401);
    }
}
