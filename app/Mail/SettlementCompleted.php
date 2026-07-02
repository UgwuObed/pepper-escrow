<?php

namespace App\Mail;

use App\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SettlementCompleted extends Mailable
{
    use Queueable, SerializesModels;

    public Merchant $merchant;
    public array $payload;

    public function __construct(Merchant $merchant, array $payload)
    {
        $this->merchant = $merchant;
        $this->payload = $payload;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Settlement Completed - ' . ($this->payload['batch_number'] ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.settlement_completed',
            with: [
                'merchant' => $this->merchant,
                'batch' => $this->payload['batch_number'] ?? '',
                'amount' => $this->payload['net_amount'] ?? 0,
                'count' => $this->payload['item_count'] ?? 0,
            ],
        );
    }
}
