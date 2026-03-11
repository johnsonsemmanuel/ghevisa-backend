<?php

namespace App\Mail;

use App\Models\AuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * FIX #9: Security Alert Email
 * Sent to security team on critical security events.
 */
class SecurityAlertNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $event,
        public AuditLog $auditLog,
        public array $context
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🚨 SECURITY ALERT: ' . strtoupper(str_replace('_', ' ', $this->event)),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security-alert',
            with: [
                'event' => $this->event,
                'auditLog' => $this->auditLog,
                'context' => $this->context,
                'user' => $this->auditLog->user,
            ],
        );
    }
}
