<?php

namespace App\Modules\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OtpCodeMail extends Mailable
{
    public function __construct(public readonly string $text) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('medconnect.otp.email_subject'));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e($this->text).'</p>');
    }
}
