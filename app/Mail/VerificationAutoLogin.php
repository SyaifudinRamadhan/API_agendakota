<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationAutoLogin extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct($user, $password)
    {
        $this->username = $user->name;
        $this->email = $user->email;
        $this->password = $password;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Account Information',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $data = [
            'name' => $this->username,
            // 'is_otp' => $this->isOtp,
            'email' => $this->email,
            'password' => $this->password,
        ];
        // if($this->isOtp){
        //     $data += [
        //         'otp_code' => $this->token
        //     ];
        // }else{
        //     $data += [
        //         'url' => route('verify', [$this->token]),
        //     ];
        // }
        return new Content(
            markdown: 'emails.verifications-auto-login',
            with: $data
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