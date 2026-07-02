<?php

namespace App\Mail;

use App\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceived extends Mailable
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
            subject: 'Payment Received - ' . ($this->payload['transcode'] ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment_received',
            with: [
                'merchant' => $this->merchant,
                'amount' => $this->payload['amount'] ?? 0,
                'transcode' => $this->payload['transcode'] ?? '',
                'customer' => $this->payload['customer_email'] ?? '',
            ],
        );
    }
}
