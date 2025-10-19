<?php

namespace App\Mail;

use App\Models\Reseller;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResellerTrafficTimeReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Reseller $reseller,
        public ?int $daysRemaining = null,
        public ?float $trafficRemainingPercent = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'هشدار محدودیت پنل ریسلر / Reseller Panel Limit Warning',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reseller-traffic-time-reminder',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
