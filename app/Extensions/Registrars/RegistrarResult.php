<?php

namespace App\Extensions\Registrars;

/**
 * The outcome of a registrar action.
 */
final readonly class RegistrarResult
{
    /**
     * @param  array<string, mixed>  $data  Values the registrar returned, for example ['expires_at' => '2027-09-27'].
     */
    private function __construct(
        public bool $success,
        public string $message,
        public array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(string $message = 'Done', array $data = []): self
    {
        return new self(true, $message, $data);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}
