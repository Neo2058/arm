<?php

namespace App\Mail;

use App\Models\AdminNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewAdminNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AdminNotification $notification)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Новое уведомление в админ-панели: ' . $this->notification->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.admin-notification',
            with: [
                'notification' => $this->notification,
            ],
        );
    }
}
