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

    public function test_only_one_name_in_front_of_the_extension_can_be_registered(): void
    {
        $this->assertTrue(DomainName::isRegistrable('shop.co.uk', ['co.uk', 'uk']));
        $this->assertTrue(DomainName::isRegistrable('mybrand.com', ['com']));
        $this->assertFalse(DomainName::isRegistrable('blog.mybrand.com', ['com']));
        $this->assertFalse(DomainName::isRegistrable('shop.co.uk', ['uk']));
        $this->assertFalse(DomainName::isRegistrable('com', ['com']));
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
            'US number with leading 1' => ['1-555-123-4567', 'US', ['1', '5551234567']],
            'US number without 1' => ['(555) 123-4567', 'US', ['1', '5551234567']],
            'Russian number with trunk 8' => ['8 912 345 67 89', 'RU', ['7', '9123456789']],
            'Russian number with 7 but no plus' => ['7 912 345 67 89', 'RU', ['7', '9123456789']],
            'Kazakh number with trunk 8' => ['8 701 123 45 67', 'KZ', ['7', '7011234567']],
            'Belarusian number with trunk 80' => ['8 029 123 45 67', 'BY', ['375', '291234567']],
            'Hungarian number with trunk 06' => ['06 30 123 4567', 'HU', ['36', '301234567']],
            'Italian landline keeps its 0' => ['06 1234 5678', 'IT', ['39', '0612345678']],
            'Italian landline from abroad' => ['+39 06 1234 5678', 'IT', ['39', '0612345678']],
            'San Marino keeps its 0' => ['0549 123456', 'SM', ['378', '0549123456']],
            'British local number' => ['020 7946 0000', 'GB', ['44', '2079460000']],
        ];
    }
}
