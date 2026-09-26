<?php

namespace App\Extensions\Servers;

/**
 * The outcome of a control panel action.
 */
final readonly class ModuleResult
{
    /**
     * @param  array<string, mixed>  $data  Extra values to store, for example ['username' => 'acme'].
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
