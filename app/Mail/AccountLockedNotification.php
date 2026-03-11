<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * SECURITY FIX MED-05: Account Lockout Notification Email
 */
class AccountLockedNotification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public User $user,
        public array $details
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Security Alert: Account Temporarily Locked',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.account-locked',
            with: [
                'user' => $this->user,
                'ip_address' => $this->details['ip_address'] ?? 'Unknown',
                'user_agent' => $this->details['user_agent'] ?? 'Unknown',
                'locked_until' => $this->details['locked_until'] ?? now()->addMinutes(15),
                'location' => $this->details['location'] ?? 'Unknown',
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
