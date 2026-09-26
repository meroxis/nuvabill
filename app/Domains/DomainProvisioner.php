<?php

namespace App\Domains;

use App\Contracts\DomainRegistrar;
use App\Enums\DomainStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Registrars\Contact;
use App\Extensions\Registrars\RegistrarResult;
use App\Mail\TemplateMailer;
use App\Models\Domain;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * Runs registrar actions for a domain and keeps its status, dates, log and emails in step.
 * Domains without a registrar are handled by staff by hand; their dates still move on.
 */
class DomainProvisioner
{
    public function __construct(
        private ExtensionManager $extensions,
        private TemplateMailer $mailer,
    ) {}

    /**
     * Register a new domain, or start the transfer of a domain ordered as a transfer.
     */
    public function register(Domain $domain): RegistrarResult
    {
        $domain->loadMissing('client');

        if (! in_array($domain->status, [DomainStatus::Pending, DomainStatus::PendingTransfer], true)) {
            return RegistrarResult::ok(__('The domain is already :status.', ['status' => mb_strtolower($domain->status->label())]));
        }

        $registrar = $this->registrarFor($domain);

        if ($registrar === null) {
            return $this->failed($domain, 'register', RegistrarResult::fail(__('No registrar is set for .:tld. Register it by hand, then mark it active.', ['tld' => $domain->tld])));
        }

        if ($registrar instanceof RegistrarResult) {
            return $this->failed($domain, 'register', $registrar);
        }

        $contact = Contact::fromClient($domain->client);

        if ($contact->missingFields() !== []) {
            return $this->failed($domain, 'register', RegistrarResult::fail(__('The client account is missing: :fields.', ['fields' => implode(', ', $contact->missingFields())])));
        }

        if ($domain->nameserverList() === []) {
            $domain->nameservers = self::defaultNameservers();
        }

        if ($domain->isTransfer()) {
            return $this->startTransfer($domain, $registrar, $contact);
        }

        $result = $this->attempt(fn (): RegistrarResult => $registrar->register($domain, $contact));

        if (! $result->success) {
            return $this->failed($domain, 'register', $result);
        }

        $today = CarbonImmutable::today();
        $expiresAt = $this->date($result->data['expires_at'] ?? null) ?? $today->addYearsNoOverflow($domain->years);

        $domain->fill([
            'status' => DomainStatus::Active,
            'registered_at' => $today,
            'expires_at' => $expiresAt,
            'next_due_date' => $expiresAt,
            'registrar_data' => array_merge((array) $domain->registrar_data, (array) ($result->data['registrar_data'] ?? [])),
            'last_synced_at' => now(),
        ])->save();

        Activity::log('domain.registered', "Domain {$domain->name} registered with {$registrar->name()}", $domain, $domain->client);
        $this->mailer->send('domain.registered', $domain->client, self::context($domain));

        return $result;
    }

    /**
     * Renew at the registrar for the given years (the domain's period by default) and move the dates forward.
     */
    public function renew(Domain $domain, ?int $years = null): RegistrarResult
    {
        $domain->loadMissing('client');
        $years ??= max(1, $domain->years);
        $registrar = $this->registrarFor($domain);

        if ($registrar instanceof RegistrarResult) {
            return $this->failed($domain, 'renew', $registrar);
        }

        $result = $registrar === null
            ? RegistrarResult::ok(__('No registrar is set. Renew it by hand at your registrar.'))
            : $this->attempt(fn (): RegistrarResult => $registrar->renew($domain, $years));

        if (! $result->success) {
            return $this->failed($domain, 'renew', $result);
        }

        $base = $domain->expires_at ?? $domain->next_due_date ?? CarbonImmutable::today();
        $expiresAt = $this->date($result->data['expires_at'] ?? null) ?? $base->addYearsNoOverflow($years);

        $domain->fill([
            'status' => DomainStatus::Active,
            'expires_at' => $expiresAt,
            'next_due_date' => $expiresAt,
            'expiry_notice_sent_at' => null,
        ])->save();

        $how = $registrar === null ? 'renew it by hand at your registrar' : 'renewed with '.$registrar->name();
        Activity::log('domain.renewed', "Domain {$domain->name} paid for {$years} more year(s): {$how}", $domain, $domain->client);
        $this->mailer->send('domain.renewed', $domain->client, self::context($domain));

        return $result;
    }

    /**
     * @param  list<string>  $nameservers
     */
    public function setNameservers(Domain $domain, array $nameservers): RegistrarResult
    {
        $nameservers = array_values(array_unique(array_filter(array_map(fn (string $ns): string => strtolower(trim($ns, " .\t")), $nameservers))));
        $registrar = $this->registrarFor($domain);

        if ($registrar instanceof RegistrarResult) {
            return $this->failed($domain, 'change the nameservers of', $registrar);
        }

        $result = $registrar === null || $domain->status !== DomainStatus::Active
            ? RegistrarResult::ok(__('Nameservers saved.'))
            : $this->attempt(fn (): RegistrarResult => $registrar->setNameservers($domain, $nameservers));

        if (! $result->success) {
            return $this->failed($domain, 'change the nameservers of', $result);
        }

        $domain->update(['nameservers' => $nameservers]);
        Activity::log('domain.nameservers', "Nameservers of {$domain->name} set to ".implode(', ', $nameservers), $domain, $domain->client);

        return $result;
    }

    /**
     * Read the expiry date, status and nameservers from the registrar.
     */
    public function sync(Domain $domain): RegistrarResult
    {
        $registrar = $this->registrarFor($domain);

        if (! $registrar instanceof DomainRegistrar) {
            return $registrar ?? RegistrarResult::fail(__('No registrar is set for this domain.'));
        }

        $result = $this->attempt(fn (): RegistrarResult => $registrar->sync($domain));

        if (! $result->success) {
            $domain->update(['last_synced_at' => now()]);

            return $result;
        }

        $expiresAt = $this->date($result->data['expires_at'] ?? null);
        $status = match ($result->data['status'] ?? null) {
            'active' => DomainStatus::Active,
            'expired' => DomainStatus::Expired,
            'transferred_away' => DomainStatus::TransferredAway,
            default => null,
        };

        $wasTransferring = $domain->status === DomainStatus::PendingTransfer;

        $domain->fill(array_filter([
            'expires_at' => $expiresAt,
            'next_due_date' => $expiresAt !== null && $domain->next_due_date === null ? $expiresAt : null,
            'status' => $status,
            'nameservers' => $result->data['nameservers'] ?? null,
        ], fn (mixed $value): bool => $value !== null));
        $domain->last_synced_at = now();

        if ($wasTransferring && $domain->status === DomainStatus::Active) {
            $domain->registered_at ??= CarbonImmutable::today();
            $domain->next_due_date = $domain->expires_at;
            Activity::log('domain.transferred', "Transfer of {$domain->name} finished", $domain, $domain->client);
        }

        $domain->save();

        return $result;
    }

    /**
     * @return list<string>
     */
    public static function defaultNameservers(): array
    {
        return array_values(array_filter((array) setting('domains.nameservers'), fn (mixed $ns): bool => is_string($ns) && $ns !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    public static function context(Domain $domain): array
    {
        return [
            'domain' => [
                'name' => $domain->name,
                'expires_at' => $domain->expires_at?->format('d M Y'),
                'years' => $domain->years,
                'nameservers' => implode(', ', $domain->nameserverList()),
                'url' => route('client.domains.show', $domain),
            ],
        ];
    }

    private function startTransfer(Domain $domain, DomainRegistrar $registrar, Contact $contact): RegistrarResult
    {
        $epp = (string) $domain->epp_code;

        if ($epp === '') {
            return $this->failed($domain, 'transfer', RegistrarResult::fail(__('The transfer needs the authorization (EPP) code from the current registrar.')));
        }

        $result = $this->attempt(fn (): RegistrarResult => $registrar->transfer($domain, $contact, $epp));

        if (! $result->success) {
            return $this->failed($domain, 'transfer', $result);
        }

        $domain->fill([
            'status' => ($result->data['status'] ?? null) === 'active' ? DomainStatus::Active : DomainStatus::PendingTransfer,
            'registrar_data' => array_merge((array) $domain->registrar_data, (array) ($result->data['registrar_data'] ?? [])),
            'epp_code' => null,
        ])->save();

        Activity::log('domain.transfer_started', "Transfer of {$domain->name} to {$registrar->name()} started", $domain, $domain->client);
        $this->mailer->send('domain.transfer_started', $domain->client, self::context($domain));

        return $result;
    }

    /**
     * The domain's registrar, null for domains handled by hand, or a failed result when it is missing or not set up.
     */
    private function registrarFor(Domain $domain): DomainRegistrar|RegistrarResult|null
    {
        if (blank($domain->registrar)) {
            return null;
        }

        try {
            $registrar = $this->extensions->registrar($domain->registrar);
        } catch (Throwable) {
            return RegistrarResult::fail(__('The registrar ":registrar" is not installed.', ['registrar' => $domain->registrar]));
        }

        if (! $this->extensions->isEnabled($domain->registrar) || ! $registrar->isConfigured()) {
            return RegistrarResult::fail(__(':registrar is switched off or missing settings.', ['registrar' => $registrar->name()]));
        }

        return $registrar;
    }

    /**
     * @param  Closure(): RegistrarResult  $callback
     */
    private function attempt(Closure $callback): RegistrarResult
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            report($exception);

            return RegistrarResult::fail($exception->getMessage());
        }
    }

    private function failed(Domain $domain, string $action, RegistrarResult $result): RegistrarResult
    {
        Activity::log('domain.registrar_failed', "Could not {$action} {$domain->name}: {$result->message}", $domain, $domain->client);

        return $result;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
