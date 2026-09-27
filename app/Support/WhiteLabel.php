<?php

namespace App\Support;

use App\Marketplace\MarketplaceClient;
use Carbon\CarbonImmutable;

/**
 * The White-label license: a yearly license from the marketplace store that removes the
 * "Powered by Nuvabill" credit from this site.
 *
 * The key is checked with the store about once a day. If the store cannot be reached, the last
 * good answer counts for {@see self::GRACE_DAYS} days, so an outage never brings the credit back.
 */
class WhiteLabel
{
    /**
     * The license's item on the marketplace store.
     */
    public const SLUG = 'white-label';

    public const GRACE_DAYS = 14;

    public function __construct(
        private MarketplaceClient $client,
        private Settings $settings,
    ) {}

    public function key(): string
    {
        return trim((string) $this->settings->get('license.white_label_key'));
    }

    /**
     * @return array{valid?: bool, status?: string, message?: string, checked_at?: string, last_valid_at?: string|null}
     */
    public function state(): array
    {
        return (array) ($this->settings->get('license.white_label') ?? []);
    }

    public function isActive(): bool
    {
        if ($this->key() === '' || Demo::isEnabled()) {
            return false;
        }

        $state = $this->state();

        return ($state['valid'] ?? false) === true;
    }

    /**
     * Save a new key (or remove it with an empty one) and check it straight away.
     *
     * @return array{valid?: bool, status?: string, message?: string, checked_at?: string, last_valid_at?: string|null}
     */
    public function saveKey(string $key): array
    {
        $key = strtoupper(trim($key));
        $this->settings->setMany(['license.white_label_key' => $key, 'license.white_label' => null]);

        return $key === '' ? [] : $this->check();
    }

    /**
     * Ask the store if the key is valid for this site, and remember the answer.
     *
     * @return array{valid?: bool, status?: string, message?: string, checked_at?: string, last_valid_at?: string|null}
     */
    public function check(): array
    {
        $key = $this->key();

        if ($key === '') {
            return [];
        }

        $previous = $this->state();
        $answer = $this->client->checkLicense(self::SLUG, $key, (string) config('nuvabill.version'));
        $now = CarbonImmutable::now();

        if ($answer === null) {
            $lastValid = isset($previous['last_valid_at']) ? CarbonImmutable::parse($previous['last_valid_at']) : null;
            $state = [
                'valid' => $lastValid !== null && $lastValid->gt($now->subDays(self::GRACE_DAYS)),
                'status' => 'unreachable',
                'message' => __('The marketplace store could not be reached. We will try again tomorrow.'),
                'checked_at' => $now->toIso8601String(),
                'last_valid_at' => $previous['last_valid_at'] ?? null,
            ];
        } else {
            $state = [
                'valid' => (bool) $answer['valid'],
                'status' => (string) $answer['status'],
                'message' => (string) $answer['message'],
                'checked_at' => $now->toIso8601String(),
                'last_valid_at' => $answer['valid'] ? $now->toIso8601String() : null,
            ];
        }

        $this->settings->set('license.white_label', $state);

        if (($previous['valid'] ?? null) !== $state['valid']) {
            Activity::log('license.white_label', $state['valid'] ? 'White-label license is active: the Nuvabill credit is hidden' : 'White-label license is not valid: '.$state['message']);
        }

        return $state;
    }
}
