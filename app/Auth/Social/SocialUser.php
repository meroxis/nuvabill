<?php

namespace App\Auth\Social;

/**
 * The profile a sign-in provider shares with us.
 */
final readonly class SocialUser
{
    public function __construct(
        public string $id,
        public ?string $email,
        public bool $emailVerified,
        public string $firstName,
        public string $lastName,
    ) {}

    /**
     * Split a full name such as "Dana Ali Hassan" into first ("Dana Ali") and last ("Hassan") name.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitName(string $name): array
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        $space = strrpos($name, ' ');

        return $space === false ? [$name, ''] : [substr($name, 0, $space), substr($name, $space + 1)];
    }
}
