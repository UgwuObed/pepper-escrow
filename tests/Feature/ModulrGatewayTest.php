<?php

namespace Tests\Feature;

use App\PaymentGateways\ModulrGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModulrGatewayTest extends TestCase
{
    private const BASE_URL = 'https://modulr.test/api-sandbox';

    private function gateway(array $overrides = []): ModulrGateway
    {
        return new ModulrGateway(array_merge([
            'api_key' => 'key_test',
            'api_secret' => 'secret_test',
            'base_url' => self::BASE_URL,
            'customer_id' => 'C1000001',
            'product_codes' => ['GBP' => 'GBP-000001', 'EUR' => 'EUR-000002'],
            'webhook_id' => 'W1000001',
            'webhook_secret' => str_repeat('s', 32),
        ], $overrides));
    }

    public function test_the_signature_matches_modulrs_documented_scheme(): void
    {
        $date = 'Mon, 02 Nov 2026 09:30:00 GMT';
        $nonce = '28154b2-9c62b93cc22a-24c9e2-5536d7d';

        $expected = rawurlencode(base64_encode(
            hash_hmac('sha1', "date: {$date}\nx-mod-nonce: {$nonce}", 'secret_test', true)
        ));

        $this->assertSame(
            'Signature keyId="key_test",algorithm="hmac-sha1",headers="date x-mod-nonce",signature="' . $expected . '"',
            ModulrGateway::sign('key_test', 'secret_test', $date, $nonce),
        );
    }

    public function test_every_request_is_signed_with_a_fresh_nonce(): void
    {
        Http::fake([self::BASE_URL . '/accounts/*' => Http::response(['id' => 'A1', 'status' => 'ACTIVE', 'currency' => 'GBP'])]);

        $gateway = $this->gateway();
        $gateway->getAccount('A1');
        $gateway->getAccount('A1');

        $nonces = [];
        Http::assertSent(function ($request) use (&$nonces) {
            $date = $request->header('Date')[0];
            $nonce = $request->header('x-mod-nonce')[0];
            $nonces[] = $nonce;

            return $request->header('Authorization')[0] === ModulrGateway::sign('key_test', 'secret_test', $date, $nonce);
        });

        $this->assertCount(2, array_unique($nonces));
    }

    public function test_create_account_issues_an_account_tagged_with_the_hold_reference(): void
    {
        Http::fake([
            self::BASE_URL . '/customers/C1000001/accounts' => Http::response([
                'id' => 'A2100001',
                'status' => 'ACTIVE',
                'currency' => 'EUR',
                'externalReference' => 'PO-2026-0412',
                'identifiers' => [
                    ['type' => 'IBAN', 'iban' => 'NL91MODR0123456789', 'bic' => 'MODRNL21'],
                ],
            ], 201),
        ]);

        $account = $this->gateway()->createAccount('eur', 'PO-2026-0412');

        $this->assertSame('A2100001', $account['account_id']);
        $this->assertSame('NL91MODR0123456789', $account['iban']);
        $this->assertSame('MODRNL21', $account['bic']);
        $this->assertNull($account['sort_code']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['currency'] === 'EUR'
            && $request['productCode'] === 'EUR-000002'
            && $request['externalReference'] === 'PO-2026-0412');
    }

    public function test_create_account_returns_uk_details_for_gbp(): void
    {
        Http::fake([
            self::BASE_URL . '/customers/C1000001/accounts' => Http::response([
                'id' => 'A2100002',
                'status' => 'ACTIVE',
                'currency' => 'GBP',
                'identifiers' => [['type' => 'SCAN', 'sortCode' => '000000', 'accountNumber' => '12345678']],
            ], 201),
        ]);

        $account = $this->gateway()->createAccount('GBP', 'ORDER-1');

        $this->assertSame('000000', $account['sort_code']);
        $this->assertSame('12345678', $account['account_number']);
    }

    public function test_create_account_refuses_a_currency_without_a_product_code(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No Modulr product code is configured for USD.');

        try {
            $this->gateway()->createAccount('USD', 'ORDER-1');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_external_references_are_made_safe_for_modulr(): void
    {
        $this->assertSame('PO-2026-0412', ModulrGateway::externalReference('PO-2026-0412'));
        $this->assertSame('order-123-a', ModulrGateway::externalReference('order:123.a'));
        $this->assertSame(50, strlen(ModulrGateway::externalReference(str_repeat('x', 80))));
    }

    public function test_payment_references_fit_the_gbp_limits(): void
    {
        $this->assertSame('PO-2026-0412', ModulrGateway::paymentReference('PO-2026-0412', 'GBP'));
        $this->assertSame(18, strlen(ModulrGateway::paymentReference(str_repeat('ab', 20), 'GBP')));
        $this->assertSame('PEPPA AB', ModulrGateway::paymentReference('AB', 'GBP'));
        $this->assertSame('PEPPA AAAAAA', ModulrGateway::paymentReference('AAAAAA', 'GBP'));
    }

    public function test_pay_out_to_a_uk_account(): void
    {
        Http::fake([self::BASE_URL . '/payments' => Http::response(['id' => 'P000001ABC', 'status' => 'SUBMITTED'], 201)]);

        $result = $this->gateway()->payOut(
            'A2100002',
            ['name' => 'Rotterdam Freight', 'sort_code' => '04-00-04', 'account_number' => '1234 5678'],
            5000,
            'GBP',
            'PO-2026-0412',
            'PO-2026-0412-freight-1',
        );

        $this->assertSame('P000001ABC', $result['payment_id']);
        $this->assertSame('pending', $result['status'], 'A submitted payment is not yet paid');

        Http::assertSent(fn ($request) => $request['sourceAccountId'] === 'A2100002'
            && $request['destination'] === ['type' => 'SCAN', 'name' => 'Rotterdam Freight', 'sortCode' => '040004', 'accountNumber' => '12345678']
            && $request['amount'] == 5000
            && $request['currency'] === 'GBP'
            && $request['externalReference'] === 'PO-2026-0412-freight-1');
    }

    public function test_pay_out_to_an_iban(): void
    {
        Http::fake([self::BASE_URL . '/payments' => Http::response(['id' => 'P000002ABC', 'status' => 'SUBMITTED'], 201)]);

        $this->gateway()->payOut('A2100001', ['name' => 'Manufacturer GmbH', 'iban' => 'de89 3704 0044 0532 0130 00'], 650, 'EUR', 'PO-1', 'PO-1-manufacturer-1');

        Http::assertSent(fn ($request) => $request['destination'] === [
            'type' => 'IBAN', 'name' => 'Manufacturer GmbH', 'iban' => 'DE89370400440532013000',
        ]);
    }

    public function test_pay_out_rejects_incomplete_bank_details_before_calling_modulr(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->gateway()->payOut('A1', ['name' => 'Someone', 'sort_code' => '123'], 10, 'GBP', 'PO-1', 'PO-1-x');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_validation_error_from_modulr_is_raised_with_its_message(): void
    {
        Http::fake([self::BASE_URL . '/payments' => Http::response([
            ['field' => 'amount', 'code' => 'FIELD_REQUIRED', 'message' => 'Amount is required'],
        ], 400)]);

        $this->expectExceptionMessage('Amount is required');

        $this->gateway()->transferBetweenAccounts('A1', 'A2', 10, 'GBP', 'PO-1', 'PO-1-move');
    }

    public function test_a_correctly_signed_webhook_is_accepted(): void
    {
        $date = 'Mon, 02 Nov 2026 09:30:00 GMT';
        $nonce = 'nonce-1';

        $headers = [
            'authorization' => [ModulrGateway::sign('W1000001', str_repeat('s', 32), $date, $nonce, 'hmac-sha256')],
            'date' => [$date],
            'x-mod-nonce' => [$nonce],
        ];

        $this->assertTrue($this->gateway()->verifyWebhookSignature('{}', $headers));
    }

    public function test_a_webhook_signed_with_the_wrong_secret_is_rejected(): void
    {
        $date = 'Mon, 02 Nov 2026 09:30:00 GMT';

        $headers = [
            'authorization' => [ModulrGateway::sign('W1000001', str_repeat('x', 32), $date, 'n')],
            'date' => [$date],
            'x-mod-nonce' => ['n'],
        ];

        $this->assertFalse($this->gateway()->verifyWebhookSignature('{}', $headers));
    }

    public function test_webhooks_are_rejected_when_no_secret_is_configured(): void
    {
        $date = 'Mon, 02 Nov 2026 09:30:00 GMT';

        $headers = [
            'authorization' => [ModulrGateway::sign('W1000001', '', $date, 'n')],
            'date' => [$date],
            'x-mod-nonce' => ['n'],
        ];

        $this->assertFalse($this->gateway(['webhook_secret' => ''])->verifyWebhookSignature('{}', $headers));
    }

    public function test_an_unsigned_webhook_is_rejected(): void
    {
        $this->assertFalse($this->gateway()->verifyWebhookSignature('{}', []));
    }

    public function test_parse_payin_normalises_the_notification(): void
    {
        $payin = ModulrGateway::parsePayin([
            'EventName' => 'PAYIN',
            'EventId' => 'E1',
            'PaymentId' => 'P0000ABCDE',
            'AccountId' => 'A2100001',
            'Amount' => '1000.00',
            'Currency' => 'eur',
            'PayerName' => 'Importer BV',
            'PaymentReference' => 'PO-2026-0412',
            'Type' => 'PI_SEPA_INST',
        ]);

        $this->assertSame([
            'payment_id' => 'P0000ABCDE',
            'account_id' => 'A2100001',
            'amount' => 1000.0,
            'currency' => 'EUR',
            'payer_name' => 'Importer BV',
            'reference' => 'PO-2026-0412',
            'type' => 'PI_SEPA_INST',
        ], $payin);
    }

    public function test_parse_payin_ignores_other_events(): void
    {
        $this->assertNull(ModulrGateway::parsePayin(['EventName' => 'PAYOUT', 'PaymentId' => 'P1', 'AccountId' => 'A1']));
        $this->assertNull(ModulrGateway::parsePayin(['EventName' => 'PAYIN', 'AccountId' => 'A1']));
    }

    public function test_hosted_checkout_is_not_supported(): void
    {
        $this->expectException(\BadMethodCallException::class);

        $this->gateway()->createPaymentLink(10, 'GBP', 'R', 'a@b.c', 'A', 'https://x');
    }
}
