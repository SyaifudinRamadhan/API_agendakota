<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InviteEvent extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct($username, $senderMail, $eventName, $ticketName, $token = null)
    {
        $this->token = $token;
        $this->username = $username;
        $this->eventName = $eventName;
        $this->ticketName = $ticketName;
        $this->senderMail = $senderMail;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invite Event',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.inviteEvent',
            with: [
                "sender" => $this->senderMail,
                "username" => $this->username,
                "eventName" => $this->eventName,
                "ticketName" => $this->ticketName,
                "token" => $this->token,
                "url" => $this->token != null ? 'redirect to verify token' : 'redirect  to login page'
            ]
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
