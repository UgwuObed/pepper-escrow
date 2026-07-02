<?php

namespace App\Services;

use App\Models\ClientConfig;
use App\Models\Merchant;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public const EVENTS = [
        'payment.received',
        'transaction.released',
        'transaction.cancelled',
        'transaction.fulfilled',
        'dispute.filed',
        'dispute.resolved',
        'settlement.completed',
        'settlement.failed',
        'subscription.billed',
        'subscription.cancelled',
        'reward.earned',
        'wallet.credited',
        'wallet.debited',
    ];

    public function notify(
        Merchant $merchant,
        string $event,
        array $payload,
        ?string $emailOverride = null,
    ): void {
        $config = ClientConfig::where('merchant_id', $merchant->id)->first();
        $settings = $config?->notification_settings ?? [];

        $eventKey = match (true) {
            str_starts_with($event, 'payment.') => 'on_payment',
            str_starts_with($event, 'transaction.') => 'on_release',
            str_starts_with($event, 'dispute.') => 'on_dispute',
            str_starts_with($event, 'settlement.') => 'on_settlement',
            default => null,
        };

        if ($eventKey && isset($settings[$eventKey]) && !$settings[$eventKey]) {
            return;
        }

        $this->sendWebhook($merchant, $event, $payload);

        if ($emailOverride) {
            $this->sendEmail($merchant, $event, $emailOverride, $payload);
        }
    }

    public function sendWebhook(Merchant $merchant, string $event, array $payload): ?NotificationLog
    {
        $webhookUrl = $merchant->webhook_url;
        $secret = $merchant->webhook_secret;

        if (!$webhookUrl) {
            return null;
        }

        $body = json_encode([
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => $payload,
        ]);

        $signature = $secret ? hash_hmac('sha256', $body, $secret) : null;

        $log = NotificationLog::create([
            'merchant_id' => $merchant->id,
            'channel' => 'webhook',
            'event' => $event,
            'recipient' => $webhookUrl,
            'subject' => $event,
            'body' => $body,
            'status' => 'pending',
            'reference_type' => $payload['reference_type'] ?? null,
            'reference_id' => $payload['reference_id'] ?? null,
        ]);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Pepper-Event' => $event,
                    'X-Pepper-Signature' => $signature,
                    'X-Pepper-Timestamp' => now()->toIso8601String(),
                ])
                ->withBody($body, 'application/json')
                ->post($webhookUrl);

            $log->attempts++;
            $log->response_code = $response->status();

            if ($response->successful()) {
                $log->markSent($response->status(), $response->body());
            } else {
                $log->markFailed($response->status(), $response->body());
                Log::warning('Webhook delivery failed', [
                    'merchant' => $merchant->id,
                    'event' => $event,
                    'url' => $webhookUrl,
                    'status' => $response->status(),
                ]);
            }

            $log->save();
        } catch (\Exception $e) {
            $log->attempts++;
            $log->markFailed(0, $e->getMessage());
            $log->save();

            Log::error('Webhook delivery exception', [
                'merchant' => $merchant->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }

        return $log->fresh();
    }

    public function sendEmail(Merchant $merchant, string $event, string $recipient, array $payload): ?NotificationLog
    {
        $subject = match ($event) {
            'payment.received' => 'Payment Received',
            'transaction.released' => 'Transaction Released',
            'transaction.fulfilled' => 'Transaction Fulfilled',
            'dispute.filed' => 'Dispute Filed',
            'dispute.resolved' => 'Dispute Resolved',
            'settlement.completed' => 'Settlement Completed',
            'reward.earned' => 'Reward Earned',
            default => 'Pepper Escrow Notification',
        };

        $log = NotificationLog::create([
            'merchant_id' => $merchant->id,
            'channel' => 'email',
            'event' => $event,
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => json_encode($payload),
            'status' => 'pending',
            'reference_type' => $payload['reference_type'] ?? null,
            'reference_id' => $payload['reference_id'] ?? null,
        ]);

        try {
            $mailableClass = $this->resolveMailable($event);
            if ($mailableClass) {
                Mail::to($recipient)->send(new $mailableClass($merchant, $payload));
            }

            $log->markSent();
        } catch (\Exception $e) {
            $log->markFailed(0, $e->getMessage());
            Log::error('Email notification failed', [
                'merchant' => $merchant->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }

        return $log->fresh();
    }

    public function notifyPaymentReceived(Merchant $merchant, array $payload): void
    {
        $this->notify($merchant, 'payment.received', $payload, $payload['customer_email'] ?? null);
    }

    public function notifyTransactionReleased(Merchant $merchant, array $payload): void
    {
        $this->notify($merchant, 'transaction.released', $payload, $payload['customer_email'] ?? null);
    }

    public function notifyDisputeFiled(Merchant $merchant, array $payload): void
    {
        $this->notify($merchant, 'dispute.filed', $payload);
    }

    public function notifySettlementCompleted(Merchant $merchant, array $payload): void
    {
        $this->notify($merchant, 'settlement.completed', $payload);
    }

    public function notifyRewardEarned(Merchant $merchant, array $payload): void
    {
        $this->notify($merchant, 'reward.earned', $payload, $payload['customer_email'] ?? null);
    }

    public function testWebhook(Merchant $merchant): ?NotificationLog
    {
        return $this->sendWebhook($merchant, 'ping', [
            'message' => 'This is a test webhook from Pepper Escrow.',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    protected function resolveMailable(string $event): ?string
    {
        $map = [
            'payment.received' => \App\Mail\PaymentReceived::class,
            'transaction.released' => \App\Mail\TransactionReleased::class,
            'settlement.completed' => \App\Mail\SettlementCompleted::class,
            'reward.earned' => \App\Mail\RewardEarned::class,
        ];

        return $map[$event] ?? null;
    }
}
