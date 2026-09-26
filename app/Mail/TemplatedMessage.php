<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use App\Support\Branding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An email built from an {@see EmailTemplate}, already rendered to HTML.
 */
class TemplatedMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.template',
            with: [
                'body' => $this->bodyHtml,
                'companyName' => setting('company.name'),
                'accent' => setting('branding.accent'),
                'showPoweredBy' => Branding::showPoweredBy(),
            ],
        );
    }
}
