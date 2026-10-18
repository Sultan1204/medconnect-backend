<?php

namespace App\Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Generic notice e-mail. Carries only the rendered generic text. */
class NotificationMail extends Mailable
{
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $text,
        public readonly string $footer,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e($this->text).'</p><hr><p style="color:#666;font-size:12px">'.e($this->footer).'</p>');
    }
}
