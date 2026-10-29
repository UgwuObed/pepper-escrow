<?php

namespace Tests\Feature;

use App\PaymentGateways\VfdGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VfdGatewayTest extends TestCase
{
    private const AUTH_URL = 'https://vfd-auth.test/v1.1';
    private const BASE_URL = 'https://vfd-wallet.test/wallet2';

    private function gateway(array $overrides = []): VfdGateway
    {
        return new VfdGateway(array_merge([
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
            'auth_url' => self::AUTH_URL,
            'base_url' => self::BASE_URL,
            'pool_account' => '1000000001',
            'validity_time' => 60,
            'webhook_secret' => 'shared-secret',
            'webhook_ips' => '',
        ], $overrides));
    }

    private function fakeAuth(): array
    {
        return [
            self::AUTH_URL . '/baasauth/token' => Http::response([
                'status' => '00',
                'data' => ['access_token' => 'tok_123', 'token_type' => 'Bearer'],
            ]),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_it_authenticates_and_sends_the_token_as_an_accesstoken_header(): void
    {
        Http::fake($this->fakeAuth() + [
            self::BASE_URL . '/bank' => Http::response([
                'status' => '00',
                'data' => ['bank' => [['code' => '044', 'name' => 'Access Bank']]],
            ]),
        ]);

        $banks = $this->gateway()->getBanks();

        $this->assertSame([['code' => '044', 'name' => 'Access Bank']], $banks);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/bank')
            && $request->header('AccessToken')[0] === 'tok_123');
    }

    public function test_it_reuses_a_cached_token_across_calls(): void
    {
        Http::fake($this->fakeAuth() + [
            self::BASE_URL . '/bank' => Http::response(['status' => '00', 'data' => ['bank' => []]]),
        ]);

        $gateway = $this->gateway();
        $gateway->getBanks();
        $gateway->getBanks();

        $tokenCalls = 0;
        Http::assertSent(function ($request) use (&$tokenCalls) {
            if (str_contains($request->url(), 'baasauth/token')) {
                $tokenCalls++;
            }
            return true;
        });

        $this->assertSame(1, $tokenCalls, 'Token should be fetched once and cached.');
    }

    public function test_it_throws_when_vfd_returns_a_non_zero_status(): void
    {
        Http::fake($this->fakeAuth() + [
            self::BASE_URL . '/transfer/recipient*' => Http::response([
                'status' => '99',
                'message' => 'Account not found',
            ]),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Account not found');

        $this->gateway()->nameEnquiry('0036145046', '044');
    }

    public function test_name_enquiry_returns_the_resolved_owner(): void
    {
        Http::fake($this->fakeAuth() + [
            self::BASE_URL . '/transfer/recipient*' => Http::response([
                'status' => '00',
                'data' => [
                    'name' => 'Uchenna Chukwueloka Nwafor',
                    'clientId' => '138421',
                    'bvn' => '22155258549',
                    'account' => ['number' => '0036145046', 'id' => '155481'],
                    'bank' => 'Access Bank',
                ],
            ]),
        ]);

        $resolved = $this->gateway()->nameEnquiry('0036145046', '044');

        $this->assertSame('Uchenna Chukwueloka Nwafor', $resolved['account_name']);
        $this->assertSame('0036145046', $resolved['account_number']);
        // Any bank other than VFD itself is an inter-bank transfer.
        $this->assertSame('inter', $resolved['transfer_type']);
    }

    public function test_name_enquiry_marks_vfd_to_vfd_transfers_as_intra(): void
    {
        Http::fake($this->fakeAuth() + [
            self::BASE_URL . '/transfer/recipient*' => Http::response([
                'status' => '00',
                'data' => ['name' => 'Jane Doe', 'account' => ['number' => '1001554818', 'id' => '1']],
            ]),
        ]);

        $resolved = $this->gateway()->nameEnquiry('1001554818', VfdGateway::VFD_BANK_CODE);

        $this->assertSame('intra', $resolved['transfer_type']);
    }

    public function test_it_creates_an_amount_bound_virtual_account(): void
    {
        Http::fake($this->fakeAuth() + [
            self::BASE_URL . '/virtualaccount' => Http::response([
                'status' => '00',
                'data' => ['accountNumber' => '1001563612', 'amount' => '34000.00'],
            ]),
        ]);

        $va = $this->gateway()->createTemporaryVirtualAccount(34000.0, 'buyer@example.com', '09012345674');

        $this->assertSame('1001563612', $va['account_number']);
        $this->assertSame('VFD Microfinance Bank', $va['bank_name']);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/virtualaccount')) {
                return true;
            }
            // Naira with two decimals, not kobo, and drawn on the pool account.
            return $request['amount'] === '34000.00'
                && $request['accountNo'] === '1000000001'
                && $request['email'] === 'buyer@example.com';
        });
    }

    public function test_transfer_signs_with_sha512_of_the_two_account_numbers(): void
    {
        Http::fake($this->fakeAuth() + [
            self::BASE_URL . '/transfer/recipient*' => Http::response([
                'status' => '00',
                'data' => [
                    'name' => 'Jane Doe',
                    'clientId' => '1',
                    'bvn' => '2',
                    'account' => ['number' => '0036145046', 'id' => '3'],
                ],
            ]),
            self::BASE_URL . '/account/enquiry*' => Http::response([
                'status' => '00',
                'data' => [
                    'accountNo' => '1000000001',
                    'accountName' => 'Pepper Pool',
                    'accountBalance' => '500000.00',
                    'clientId' => '9',
                    'accountId' => '8',
                ],
            ]),
            self::BASE_URL . '/transfer' => Http::response([
                'status' => '00',
                'data' => ['txnId' => 'TXN-1', 'sessionId' => 'SESS-1'],
            ]),
        ]);

        $result = $this->gateway()->initiateTransfer(
            ['account_number' => '0036145046', 'bank_code' => '044'],
            12500.5,
            'BATCH-001',
            'Settlement batch BATCH-001',
        );

        $this->assertSame('TXN-1', $result['transfer_code']);

        Http::assertSent(function ($request) {
            if ($request->url() !== self::BASE_URL . '/transfer') {
                return true;
            }

            return $request['signature'] === hash('sha512', '1000000001' . '0036145046')
                && $request['amount'] === '12500.50'
                && $request['transferType'] === 'inter';
        });
    }

    public function test_hosted_checkout_and_refunds_are_rejected(): void
    {
        $gateway = $this->gateway();

        $this->expectException(\BadMethodCallException::class);
        $gateway->createPaymentLink(100.0, 'NGN', 'ref', 'a@b.com', 'A B', 'https://cb.test');
    }

    public function test_webhook_is_rejected_when_no_secret_is_configured(): void
    {
        $gateway = $this->gateway(['webhook_secret' => '']);

        $this->assertFalse($gateway->verifyWebhookSignature('{}', ['x-vfd-signature' => 'anything']));
    }

    public function test_webhook_requires_the_shared_secret(): void
    {
        $gateway = $this->gateway();

        $this->assertFalse($gateway->verifyWebhookSignature('{}', []));
        $this->assertFalse($gateway->verifyWebhookSignature('{}', ['x-vfd-signature' => 'wrong']));
        $this->assertTrue($gateway->verifyWebhookSignature('{}', ['x-vfd-signature' => 'shared-secret']));
    }

    public function test_webhook_honours_the_ip_allow_list(): void
    {
        $gateway = $this->gateway(['webhook_ips' => '10.0.0.1, 10.0.0.2']);

        $this->assertTrue($gateway->verifyWebhookSignature('{}', [
            'x-vfd-signature' => 'shared-secret',
            'x-forwarded-for' => '10.0.0.2',
        ]));

        $this->assertFalse($gateway->verifyWebhookSignature('{}', [
            'x-vfd-signature' => 'shared-secret',
            'x-forwarded-for' => '203.0.113.9',
        ]));
    }
}
