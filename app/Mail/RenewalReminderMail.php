<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RenewalReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $planName,
        public string $expiresAt,
        public int $daysRemaining
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'یادآوری تمدید اشتراک / Subscription Renewal Reminder',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.renewal-reminder',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
