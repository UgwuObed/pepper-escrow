<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\PaymentGateways\StripeConnectGateway;
use Carbon\Carbon;
use Tests\TestCase;

class StripeConnectGatewayTest extends TestCase
{
    private function gateway(): StripeConnectGateway
    {
        return new StripeConnectGateway([
            'secret_key' => 'sk_test_connect',
            'public_key' => 'pk_test_connect',
            'webhook_secret' => 'whsec_platform',
            'connect_webhook_secret' => 'whsec_connect',
        ]);
    }

    // ─── Holding periods ──────────────────────────────────────────────

    public function test_holding_periods_match_stripe_published_limits(): void
    {
        $gateway = $this->gateway();

        // https://docs.stripe.com/connect/manual-payouts
        $this->assertSame(730, $gateway->holdingPeriodDays('US'));
        $this->assertSame(90, $gateway->holdingPeriodDays('GB'));
        $this->assertSame(10, $gateway->holdingPeriodDays('TH'));
    }

    public function test_unlisted_countries_fall_back_to_ninety_days(): void
    {
        $this->assertSame(90, $this->gateway()->holdingPeriodDays('DE'));
    }

    public function test_country_code_casing_does_not_change_the_limit(): void
    {
        $gateway = $this->gateway();

        $this->assertSame(730, $gateway->holdingPeriodDays('us'));
        $this->assertSame(10, $gateway->holdingPeriodDays('th'));
    }

    public function test_hold_expiry_is_measured_from_the_charge(): void
    {
        $charged = Carbon::parse('2026-01-01 12:00:00');

        $this->assertSame(
            '2026-04-01',
            $this->gateway()->holdExpiresAt('GB', $charged)->toDateString()
        );

        $this->assertSame(
            '2028-01-01',
            $this->gateway()->holdExpiresAt('US', $charged)->toDateString()
        );
    }

    // ─── Amounts ──────────────────────────────────────────────────────

    public function test_zero_decimal_currencies_are_not_multiplied(): void
    {
        $gateway = $this->gateway();
        $toMinor = (new \ReflectionClass($gateway))->getMethod('toMinorUnits');
        $toMinor->setAccessible(true);

        // 1000 JPY is 1000, not 100000.
        $this->assertSame(1000, $toMinor->invoke($gateway, 1000.0, 'JPY'));
        $this->assertSame(1000, $toMinor->invoke($gateway, 10.0, 'GBP'));
        $this->assertSame(1050, $toMinor->invoke($gateway, 10.50, 'USD'));
    }

    public function test_minor_unit_conversion_round_trips(): void
    {
        $gateway = $this->gateway();
        $r = new \ReflectionClass($gateway);
        $to = $r->getMethod('toMinorUnits');
        $from = $r->getMethod('fromMinorUnits');
        $to->setAccessible(true);
        $from->setAccessible(true);

        foreach ([['GBP', 19.99], ['USD', 0.01], ['JPY', 5000.0]] as [$currency, $amount]) {
            $this->assertSame(
                $amount,
                $from->invoke($gateway, $to->invoke($gateway, $amount, $currency), $currency),
                "round trip failed for {$currency}"
            );
        }
    }

    // ─── Charges must name a seller ───────────────────────────────────

    public function test_platform_payment_link_is_rejected(): void
    {
        $this->expectException(\BadMethodCallException::class);

        $this->gateway()->createPaymentLink(10.0, 'GBP', 'ref', 'a@b.com', 'A B', 'https://cb.test');
    }

    public function test_verifying_without_a_connected_account_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->gateway()->verifyPayment('cs_test_123');
    }

    public function test_refunding_without_a_connected_account_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->gateway()->processRefund('pi_test_123', 10.0, 'duplicate');
    }

    // ─── Webhook signatures ───────────────────────────────────────────

    private function signed(string $payload, string $secret): string
    {
        $t = time();

        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, $secret);
    }

    public function test_connect_endpoint_secret_is_accepted(): void
    {
        $payload = '{"id":"evt_1","object":"event"}';

        $this->assertTrue($this->gateway()->verifyWebhookSignature(
            $payload,
            ['stripe-signature' => $this->signed($payload, 'whsec_connect')]
        ));
    }

    public function test_platform_endpoint_secret_is_also_accepted(): void
    {
        $payload = '{"id":"evt_2","object":"event"}';

        $this->assertTrue($this->gateway()->verifyWebhookSignature(
            $payload,
            ['stripe-signature' => $this->signed($payload, 'whsec_platform')]
        ));
    }

    public function test_unknown_secret_and_missing_header_are_rejected(): void
    {
        $payload = '{"id":"evt_3","object":"event"}';
        $gateway = $this->gateway();

        $this->assertFalse($gateway->verifyWebhookSignature(
            $payload,
            ['stripe-signature' => $this->signed($payload, 'whsec_wrong')]
        ));

        $this->assertFalse($gateway->verifyWebhookSignature($payload, []));
    }

    public function test_body_tampering_invalidates_the_signature(): void
    {
        $signature = $this->signed('{"amount":100}', 'whsec_connect');

        $this->assertFalse($this->gateway()->verifyWebhookSignature('{"amount":999999}', [
            'stripe-signature' => $signature,
        ]));
    }
}
