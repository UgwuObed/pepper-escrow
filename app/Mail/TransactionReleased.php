<?php

namespace App\Mail;

use App\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TransactionReleased extends Mailable
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
            subject: 'Transaction Released - ' . ($this->payload['transcode'] ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.transaction_released',
            with: [
                'merchant' => $this->merchant,
                'transcode' => $this->payload['transcode'] ?? '',
                'amount' => $this->payload['amount'] ?? 0,
            ],
        );
    }
}
