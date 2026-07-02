<?php

namespace App\Mail;

use App\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RewardEarned extends Mailable
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
            subject: 'Reward Earned!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reward_earned',
            with: [
                'merchant' => $this->merchant,
                'amount' => $this->payload['reward_amount'] ?? 0,
                'type' => $this->payload['reward_type'] ?? 'points',
                'balance' => $this->payload['balance'] ?? 0,
            ],
        );
    }
}
