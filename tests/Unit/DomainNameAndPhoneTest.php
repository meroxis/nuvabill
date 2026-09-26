<?php

namespace Tests\Unit;

use App\Domains\DomainName;
use App\Support\CallingCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DomainNameAndPhoneTest extends TestCase
{
    #[DataProvider('domainInputs')]
    public function test_domain_names_are_cleaned_up(string $input, ?string $expected): void
    {
        $this->assertSame($expected, DomainName::normalize($input));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function domainInputs(): array
    {
        return [
            'plain' => ['example.com', 'example.com'],
            'url with www' => ['https://www.Example.com/shop?x=1', 'example.com'],
            'trailing dot' => ['example.co.uk.', 'example.co.uk'],
            'www is the name' => ['www.com', 'www.com'],
            'no extension' => ['example', null],
            'leading dash' => ['-bad.com', null],
            'spaces' => ['my shop.com', null],
        ];
    }

    public function test_the_longest_known_extension_wins(): void
    {
        $this->assertSame(['shop', 'co.uk'], DomainName::split('shop.co.uk', ['uk', 'co.uk', 'com']));
        $this->assertSame(['shop.co', 'uk'], DomainName::split('shop.co.uk', ['uk']));
        $this->assertSame(['shop', 'dev'], DomainName::split('shop.dev'));
    }

    #[DataProvider('phones')]
    public function test_phone_numbers_are_split_for_registrars(string $phone, string $country, ?array $expected): void
    {
        $this->assertSame($expected, CallingCodes::split($phone, $country));
    }

    /**
     * @return array<string, array{string, string, array{0: string, 1: string}|null}>
     */
    public static function phones(): array
    {
        return [
            'local Iraqi number' => ['0750 123 4567', 'IQ', ['964', '7501234567']],
            'international format' => ['+964 750 123 4567', 'IQ', ['964', '7501234567']],
            'double zero prefix' => ['00964-750-123-4567', 'IQ', ['964', '7501234567']],
            'foreign number for another country' => ['+44 20 7946 0000', 'IQ', ['44', '2079460000']],
            'empty' => ['', 'IQ', null],
        ];
    }
}
