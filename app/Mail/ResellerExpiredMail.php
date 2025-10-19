<?php

namespace App\Mail;

use App\Models\Reseller;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResellerExpiredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Reseller $reseller
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'پنل ریسلر شما منقضی شده است / Your Reseller Panel Has Expired',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reseller-expired',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
