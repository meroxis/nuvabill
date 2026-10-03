<?php

namespace App\Mail;

use Stringable;

/**
 * A placeholder value that is Markdown on purpose, because Nuvabill wrote it (for example the list
 * of problems in a site health alert). Every other value goes into emails as plain text, so names
 * and ticket messages cannot add links or pictures.
 */
final readonly class MarkdownValue implements Stringable
{
    public function __construct(public string $markdown) {}

    public function __toString(): string
    {
        return $this->markdown;
    }
}
