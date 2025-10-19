<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NormalUserExpiredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $planName,
        public string $expiresAt
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'اشتراک VPN شما منقضی شده است / Your VPN Subscription Has Expired',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.normal-user-expired',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
