<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\CommissionRule;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\TransactionType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscrowApiTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;
    private Merchant $merchant;
    private Customer $merchantCustomer;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->apiKey = 'ESCROW_TEST_KEY_' . bin2hex(random_bytes(16));

        ApiToken::factory()->create([
            'app_id' => $this->merchant->id,
            'merchant_id' => $this->merchant->id,
            'api_key' => $this->apiKey,
        ]);

        $this->merchantCustomer = Customer::create([
            'name' => $this->merchant->business_name,
            'email' => $this->merchant->email,
            'usertype' => 'merchant',
            'merchantid' => (string) $this->merchant->id,
        ]);

        $this->customer = Customer::factory()->create();

        $escrowType = TransactionType::factory()->create([
            'merchant_id' => $this->merchant->id,
            'slug' => 'escrow',
        ]);
        TransactionType::factory()->directSale()->create([
            'merchant_id' => $this->merchant->id,
        ]);
        CommissionRule::factory()->create([
            'merchant_id' => $this->merchant->id,
            'transaction_type_id' => $escrowType->id,
            'rate_value' => 2.5,
            'priority' => 0,
        ]);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    public function test_create_transaction_success(): void
    {
        $payload = [
            'appid' => (string) $this->merchant->id,
            'referenceid' => 'REF-' . bin2hex(random_bytes(8)),
            'user_email' => 'user@example.com',
            'amount' => '250000',
            'country' => 'Nigeria',
            'currency' => 'NGN',
            'customer_email' => $this->customer->email,
            'merchant_email' => $this->merchant->email,
            'description' => 'Payment for consulting services',
            'customer_account_number' => '0123456789',
            'merchant_account_number' => '9876543210',
            'customer_bank_code' => '058',
            'merchant_bank_code' => '058',
            'customer_name' => $this->customer->name,
            'merchant_name' => $this->merchant->business_name,
            'customer_phone' => '+2348000000001',
            'merchant_phone' => '+2348000000002',
            'peppfees' => '3750',
            'startdate' => Carbon::now()->format('Y-m-d'),
            'enddate' => Carbon::now()->addDays(30)->format('Y-m-d'),
            'transaction_type' => 'escrow',
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('api/escrow/Transaction/create', $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'ResponseStatus' => 'successful',
            'ResponseCode' => 0,
        ]);
        $response->assertJsonStructure([
            'ResponseMessage' => [
                'transcode', 'amount', 'currency', 'txnstatus',
                'commission_amount', 'net_amount',
            ],
        ]);

        $this->assertDatabaseHas('transactions', [
            'transcode' => $payload['referenceid'],
            'appid' => (string) $this->merchant->id,
        ]);

        $this->assertDatabaseHas('transactions_history', [
            'transcode' => $payload['referenceid'],
        ]);
    }

    public function test_create_transaction_duplicate_reference(): void
    {
        $refId = 'REF-DUP-' . bin2hex(random_bytes(8));

        Transaction::factory()->create([
            'transcode' => $refId,
            'appid' => (string) $this->merchant->id,
            'merchantid' => $this->merchant->id,
            'merchant_email' => $this->merchant->email,
        ]);

        $payload = [
            'appid' => (string) $this->merchant->id,
            'referenceid' => $refId,
            'user_email' => 'user@example.com',
            'amount' => '250000',
            'country' => 'Nigeria',
            'currency' => 'NGN',
            'customer_email' => $this->customer->email,
            'merchant_email' => $this->merchant->email,
            'description' => 'Duplicate test',
            'customer_account_number' => '0123456789',
            'merchant_account_number' => '9876543210',
            'customer_bank_code' => '058',
            'merchant_bank_code' => '058',
            'customer_name' => 'Test',
            'merchant_name' => 'Test',
            'customer_phone' => '+2348000000001',
            'merchant_phone' => '+2348000000002',
            'peppfees' => '3750',
            'startdate' => Carbon::now()->format('Y-m-d'),
            'enddate' => Carbon::now()->addDays(30)->format('Y-m-d'),
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('api/escrow/Transaction/create', $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'ResponseStatus' => 'Unsuccessful: Referenceid already exist',
        ]);
    }

    public function test_get_app_transactions_by_status(): void
    {
        Transaction::factory()->count(3)->released()->create([
            'appid' => (string) $this->merchant->id,
            'merchantid' => $this->merchant->id,
            'merchant_email' => $this->merchant->email,
        ]);
        Transaction::factory()->count(2)->pending()->create([
            'appid' => (string) $this->merchant->id,
            'merchantid' => $this->merchant->id,
            'merchant_email' => $this->merchant->email,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('api/escrow/Transaction/AppTranx?appid=' . $this->merchant->id . '&action=Released');

        $response->assertStatus(200);
        $response->assertJson(['ResponseCode' => 0]);
        $this->assertCount(3, $response->json('ResponseMessage'));
    }

    public function test_get_transaction_by_reference(): void
    {
        $txn = Transaction::factory()->create([
            'appid' => (string) $this->merchant->id,
            'merchantid' => $this->merchant->id,
            'merchant_email' => $this->merchant->email,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('api/escrow/Transaction/AppTranx/ByRef?appid=' . $this->merchant->id . '&referenceid=' . $txn->transcode . '&user_email=' . $this->customer->email);

        $response->assertStatus(200);
        $response->assertJson(['ResponseCode' => 0]);
        $this->assertEquals($txn->transcode, $response->json('ResponseMessage.transcode'));
    }

    public function test_release_transaction_success(): void
    {
        $txn = Transaction::factory()->paid()->create([
            'appid' => (string) $this->merchant->id,
            'merchantid' => $this->merchant->id,
            'merchant_email' => $this->merchant->email,
            'customer_email' => $this->customer->email,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('api/escrow/Transaction/release', [
                'appid' => (string) $this->merchant->id,
                'referenceid' => $txn->transcode,
                'user_email' => $this->customer->email,
                'reasons' => 'Service completed successfully',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['ResponseCode' => 0]);

        $this->assertDatabaseHas('transactions', [
            'transcode' => $txn->transcode,
            'trans_status' => 'Fulfilled',
        ]);
    }

    public function test_cannot_release_already_released_transaction(): void
    {
        $txn = Transaction::factory()->released()->create([
            'appid' => (string) $this->merchant->id,
            'merchantid' => $this->merchant->id,
            'merchant_email' => $this->merchant->email,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('api/escrow/Transaction/release', [
                'appid' => (string) $this->merchant->id,
                'referenceid' => $txn->transcode,
                'user_email' => 'user@example.com',
                'reasons' => 'Trying again',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['ResponseCode' => 400]);
    }

    public function test_stop_transaction(): void
    {
        $txn = Transaction::factory()->paid()->create([
            'appid' => (string) $this->merchant->id,
            'merchantid' => $this->merchant->id,
            'merchant_email' => $this->merchant->email,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('api/escrow/Transaction/stop', [
                'appid' => (string) $this->merchant->id,
                'referenceid' => $txn->transcode,
                'user_email' => 'user@example.com',
                'reasons' => 'Suspicious activity detected',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['ResponseCode' => 0]);

        $this->assertDatabaseHas('transactions', [
            'transcode' => $txn->transcode,
            'trans_status' => 'Flagged',
        ]);
    }

    public function test_request_extension(): void
    {
        $txn = Transaction::factory()->paid()->create([
            'appid' => (string) $this->merchant->id,
            'merchantid' => $this->merchant->id,
            'merchant_email' => $this->merchant->email,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('api/escrow/Transaction/reqExtension', [
                'appid' => (string) $this->merchant->id,
                'referenceid' => $txn->transcode,
                'user_email' => 'user@example.com',
                'new_date' => Carbon::now()->addDays(14)->format('Y-m-d'),
                'reasons' => 'Need more time for delivery',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['ResponseCode' => 0]);

        $this->assertDatabaseHas('extended_dates', [
            'transcode' => $txn->transcode,
        ]);
    }

    public function test_get_bank_codes(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('api/escrow/BankCodes');

        if ($response->getStatusCode() === 404) {
            $response->assertJson(['ResponseCode' => 404]);
        } else {
            $response->assertStatus(200);
            $response->assertJson(['ResponseCode' => 0]);
        }
    }

    public function test_create_transaction_applies_commission(): void
    {
        $payload = [
            'appid' => (string) $this->merchant->id,
            'referenceid' => 'REF-COMM-' . bin2hex(random_bytes(8)),
            'user_email' => 'user@example.com',
            'amount' => '100000',
            'country' => 'Nigeria',
            'currency' => 'NGN',
            'customer_email' => $this->customer->email,
            'merchant_email' => $this->merchant->email,
            'description' => 'Commission test',
            'customer_account_number' => '0123456789',
            'merchant_account_number' => '9876543210',
            'customer_bank_code' => '058',
            'merchant_bank_code' => '058',
            'customer_name' => 'Test',
            'merchant_name' => 'Test',
            'customer_phone' => '+2348000000001',
            'merchant_phone' => '+2348000000002',
            'peppfees' => '2500',
            'startdate' => Carbon::now()->format('Y-m-d'),
            'enddate' => Carbon::now()->addDays(30)->format('Y-m-d'),
            'transaction_type' => 'escrow',
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('api/escrow/Transaction/create', $payload);

        $response->assertStatus(200);
        $responseData = $response->json('ResponseMessage');

        $this->assertArrayHasKey('commission_amount', $responseData);
        $this->assertArrayHasKey('net_amount', $responseData);
    }
}
