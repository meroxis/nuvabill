<?php

namespace App\Extensions\Registrars;

use App\Models\Client;
use App\Support\CallingCodes;

/**
 * The owner contact sent to a registrar, built from the client's account details.
 */
final readonly class Contact
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $company,
        public string $email,
        public string $phoneCountryCode,
        public string $phoneNumber,
        public string $address1,
        public string $address2,
        public string $city,
        public string $state,
        public string $postcode,
        public string $country,
    ) {}

    public static function fromClient(Client $client): self
    {
        [$phoneCode, $phoneNumber] = CallingCodes::split($client->phone, $client->country) ?? ['', ''];

        return new self(
            firstName: trim((string) $client->first_name),
            lastName: trim((string) $client->last_name),
            company: trim((string) $client->company_name),
            email: (string) $client->email,
            phoneCountryCode: $phoneCode,
            phoneNumber: $phoneNumber,
            address1: trim((string) $client->address_1),
            address2: trim((string) $client->address_2),
            city: trim((string) $client->city),
            state: trim((string) $client->state) ?: trim((string) $client->city),
            postcode: trim((string) $client->postcode) ?: '00000',
            country: strtoupper(trim((string) $client->country)),
        );
    }

    /**
     * Names of the account fields that are missing. Registrars refuse contacts without them.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        return array_keys(array_filter([
            __('first name') => $this->firstName === '',
            __('last name') => $this->lastName === '',
            __('email') => $this->email === '',
            __('phone number') => $this->phoneNumber === '',
            __('address') => $this->address1 === '',
            __('city') => $this->city === '',
            __('country') => strlen($this->country) !== 2,
        ]));
    }

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    /**
     * The phone in the "+CC.NUMBER" format most registrars use, for example +964.7501234567.
     */
    public function dottedPhone(): string
    {
        return '+'.$this->phoneCountryCode.'.'.$this->phoneNumber;
    }
}
