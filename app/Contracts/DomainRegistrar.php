<?php

namespace App\Contracts;

use App\Extensions\Registrars\Contact;
use App\Extensions\Registrars\RegistrarResult;
use App\Models\Domain;

/**
 * Talks to a domain registrar (ResellerClub, Namecheap, Enom, OpenSRS, ...) to register and manage domains.
 */
interface DomainRegistrar
{
    public function slug(): string;

    public function name(): string;

    /**
     * Fields shown on the registrar's settings page.
     *
     * @return array<string, array{label: string, type: string, help?: string, required?: bool, options?: array<string, string>}>
     */
    public function settingsFields(): array;

    public function isConfigured(): bool;

    public function testConnection(): RegistrarResult;

    /**
     * Whether each domain can be registered: true (free), false (taken) or null (unknown).
     *
     * @param  list<string>  $domains  Full names, for example ["example.com", "example.net"].
     * @return array<string, bool|null>
     */
    public function checkAvailability(array $domains): array;

    /**
     * Register the domain for the domain's number of years. On success, data may hold
     * "expires_at" (Y-m-d) and "registrar_data" (values to keep, such as the registrar's order ID).
     */
    public function register(Domain $domain, Contact $contact): RegistrarResult;

    /**
     * Start a transfer to us with the authorization (EPP) code.
     */
    public function transfer(Domain $domain, Contact $contact, string $eppCode): RegistrarResult;

    /**
     * Renew for the given number of years. On success, data may hold the new "expires_at".
     */
    public function renew(Domain $domain, int $years): RegistrarResult;

    /**
     * @param  list<string>  $nameservers
     */
    public function setNameservers(Domain $domain, array $nameservers): RegistrarResult;

    /**
     * Read the domain's current state. On success, data may hold "expires_at", "status"
     * ("active", "expired" or "transferred_away") and "nameservers".
     */
    public function sync(Domain $domain): RegistrarResult;
}
