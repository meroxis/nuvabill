<?php

namespace Nuvabill\Extensions\Virtualizor;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceAddon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * Extra VPS resources sold as product add-ons: more CPU cores, RAM, disk or IPv4 addresses.
 * Staff set them per product in the "resource_addons" setting, as compact JSON:
 *
 *   {"version":1,"ids":{"cpu":5,"ram":6,"disk":7,"ipv4":8}}
 *
 * "ids" names the add-ons of each kind: one ID, a list of IDs, or {"ID": amount} for add-ons of
 * different sizes. Each add-on adds one step: 1 core, 2048 MB RAM, 40 GB disk or 1 IPv4, unless
 * "steps" sets other amounts. Optional checks: "base" (cores, ram, disk, num_ips and bandwidth of
 * the plan, compared with Virtualizor), "plan_id" and "server_id" (must be the product's),
 * "public_pool_id" (every IPv4 comes from this IP pool) and "max" (the highest totals).
 *
 * Add-ons the policy does not name are left alone, so a product without a policy works as before.
 */
final class ResourceAddons
{
    /**
     * The product setting with the policy.
     */
    public const FIELD = 'resource_addons';

    /**
     * Module data key: the note of a create with add-on extras, so a request is never sent twice.
     */
    public const KEY = 'virtualizor_resource_addons_v1';

    /**
     * The create request was sent, and it is not known yet whether it made a VPS.
     */
    public const REQUESTED = 'requested';

    /**
     * Virtualizor made the VPS; it is not checked yet.
     */
    public const CREATED = 'created';

    /**
     * The VPS was checked against the paid extras.
     */
    public const VERIFIED = 'verified';

    /**
     * Add-on kinds and the resource each one raises.
     */
    private const KINDS = ['cpu' => 'cores', 'ram' => 'ram', 'disk' => 'disk', 'ipv4' => 'num_ips'];

    /**
     * What one add-on adds when the policy sets no other amount: cores, MB, GB and addresses.
     */
    private const STEPS = ['cpu' => 1, 'ram' => 2048, 'disk' => 40, 'ipv4' => 1];

    /**
     * The highest totals when the policy sets no "max".
     */
    private const MAXIMUMS = ['cores' => 64, 'ram' => 262144, 'disk' => 16384, 'num_ips' => 16, 'bandwidth' => 10000000];

    private const SETTINGS = ['version', 'server_id', 'public_pool_id', 'plan_id', 'base', 'ids', 'steps', 'max'];

    /**
     * The product's policy, checked, or null when it has none.
     *
     * @return array{ids: array<int, array{0: string, 1: int}>, base: array<string, int>, max: array<string, int>, server_id: int|null, plan_id: int|null, public_pool_id: int|null}|null
     */
    public static function policy(Service $service): ?array
    {
        $raw = $service->product?->module_config[self::FIELD] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $policy = json_decode($raw, true);

        self::ensure(is_array($policy) && ! array_is_list($policy) && ($policy['version'] ?? null) === 1, __('it must be a JSON object with "version":1'));
        self::ensure(array_diff(array_keys($policy), self::SETTINGS) === [], __('it has a setting this version does not know'));

        $steps = self::STEPS;

        foreach (self::object($policy['steps'] ?? [], 'steps') as $kind => $amount) {
            self::ensure(array_key_exists($kind, self::KINDS), __('"steps" only takes cpu, ram, disk and ipv4'));
            $steps[$kind] = self::number($amount, 1, 'steps');
        }

        $ids = [];
        $kinds = self::object($policy['ids'] ?? null, 'ids');
        self::ensure($kinds !== [], __('"ids" needs at least one add-on'));

        foreach ($kinds as $kind => $value) {
            self::ensure(array_key_exists($kind, self::KINDS), __('"ids" only takes cpu, ram, disk and ipv4'));

            $entries = match (true) {
                is_array($value) && array_is_list($value) => array_map(fn (mixed $id): array => [$id, $steps[$kind]], $value),
                is_array($value) => array_map(fn (mixed $id, mixed $amount): array => [$id, $amount], array_keys($value), $value),
                default => [[$value, $steps[$kind]]],
            };

            self::ensure($entries !== [], __('"ids" needs at least one add-on'));

            foreach ($entries as [$id, $amount]) {
                $id = self::number($id, 1, 'ids');
                self::ensure(! array_key_exists($id, $ids), __('an add-on ID is listed twice'));
                $ids[$id] = [(string) $kind, self::number($amount, 1, 'ids')];
            }
        }

        $base = [];

        foreach (self::object($policy['base'] ?? [], 'base') as $field => $value) {
            self::ensure(array_key_exists($field, self::MAXIMUMS), __('"base" only takes cores, ram, disk, num_ips and bandwidth'));
            $base[(string) $field] = self::number($value, $field === 'bandwidth' ? 0 : 1, 'base');
        }

        $max = self::MAXIMUMS;

        foreach (self::object($policy['max'] ?? [], 'max') as $field => $value) {
            self::ensure(array_key_exists($field, self::MAXIMUMS), __('"max" only takes cores, ram, disk, num_ips and bandwidth'));
            $max[(string) $field] = self::number($value, 1, 'max');
        }

        return [
            'ids' => $ids,
            'base' => $base,
            'max' => $max,
            'server_id' => isset($policy['server_id']) ? self::number($policy['server_id'], 1, 'server_id') : null,
            'plan_id' => isset($policy['plan_id']) ? self::number($policy['plan_id'], 1, 'plan_id') : null,
            'public_pool_id' => isset($policy['public_pool_id']) ? self::number($policy['public_pool_id'], 1, 'public_pool_id') : null,
        ];
    }

    /**
     * What the service's active add-ons add under the policy, by resource. Other add-ons are skipped.
     *
     * @param  array{ids: array<int, array{0: string, 1: int}>}  $policy
     * @return array{add: array<string, int>, addons: list<int>, rows: list<ServiceAddon>}
     */
    public static function extras(Service $service, array $policy): array
    {
        $add = [];
        $addons = [];
        $rows = [];

        foreach ($service->addons()->where('status', ServiceAddon::STATUS_ACTIVE)->orderBy('id')->get() as $addon) {
            $entry = $policy['ids'][(int) $addon->product_addon_id] ?? null;

            if ($entry === null) {
                continue;
            }

            [$kind, $amount] = $entry;
            $field = self::KINDS[$kind];
            $add[$field] = ($add[$field] ?? 0) + $amount;
            $addons[] = (int) $addon->product_addon_id;
            $rows[] = $addon;
        }

        return ['add' => $add, 'addons' => $addons, 'rows' => $rows];
    }

    /**
     * Throws unless every add-on was on the service's paid order invoice. Add-ons only come with
     * an order, so a missing or unpaid line means the extras are not paid for.
     *
     * @param  list<ServiceAddon>  $addons
     */
    public static function assertPaid(Service $service, array $addons): void
    {
        $order = $service->order;
        $invoice = $order?->invoice;

        self::check($order !== null && $invoice !== null && (int) $order->client_id === (int) $service->client_id && ! $order->needs_review, __('The resource add-ons of this VPS are not on a checked order, so no VPS was made. Check the order.'));
        self::check($invoice->status === InvoiceStatus::Paid && (int) $invoice->client_id === (int) $service->client_id, __('The order invoice with the resource add-ons is not paid yet, so no VPS was made.'));
        self::check(! $invoice->creditNotes()->exists(), __('The order invoice has a credit note, so its resource add-ons need a check by hand. No VPS was made.'));

        $lines = $invoice->items()
            ->where('service_id', $service->id)
            ->where('type', InvoiceItem::TYPE_ADDON)
            ->whereNotNull('period_start')
            ->orderBy('id')
            ->get();
        $used = [];

        foreach ($addons as $addon) {
            $name = mb_substr((string) $addon->name, 0, 100);
            $line = $lines->first(fn (InvoiceItem $item): bool => ! in_array($item->id, $used, true)
                && $name !== ''
                && str_starts_with((string) $item->description, $name)
                && ($addon->recurring_amount === 0 || $item->amount === $addon->recurring_amount));

            self::check($line !== null, __('A resource add-on is not on the paid order invoice, so no VPS was made. Check the order.'));
            $used[] = $line->id;
        }
    }

    /**
     * The note of a create with add-on extras, or null when there is none or nothing was sent yet.
     * Notes from before version 1.1.0 keep their state next to a signed "snapshot"; they are read too.
     * "server_id" is the Nuvabill server the request went to; older notes have none.
     *
     * @return array{state: string, vpsid: string|null, server_id: int|null, hostname: string|null, email: string|null, totals: array<string, int>, addons: list<int>, pool: int|null, requested_at: int|null}|null
     */
    public static function record(Service $service): ?array
    {
        $raw = ((array) $service->module_data)[self::KEY] ?? null;

        if (! is_array($raw)) {
            return null;
        }

        $old = is_array($raw['state'] ?? null);
        $note = $old ? $raw['state'] : $raw;
        $state = match ((string) ($old ? ($note['status'] ?? '') : ($note['state'] ?? ''))) {
            'requested' => self::REQUESTED,
            'candidate', 'created' => self::CREATED,
            'verified' => self::VERIFIED,
            default => null,
        };

        if ($state === null) {
            return null;
        }

        $vpsId = $note['vpsid'] ?? null;
        $vpsId = (is_int($vpsId) || is_string($vpsId)) && ctype_digit((string) $vpsId) ? (string) $vpsId : null;
        $snapshot = $old ? (array) ($raw['snapshot'] ?? []) : [];
        $totals = $old ? (array) ($snapshot['totals'] ?? []) : (array) ($raw['totals'] ?? []);
        $addons = $old ? array_column((array) ($snapshot['selected'] ?? []), 'product_addon_id') : (array) ($raw['addons'] ?? []);
        $pool = $old ? ($snapshot['policy']['public_pool_id'] ?? null) : ($raw['pool'] ?? null);

        return [
            // A VPS that was made but has no ID is as unclear as a request without an answer.
            'state' => $vpsId === null ? self::REQUESTED : $state,
            'vpsid' => $vpsId,
            'server_id' => ! $old && is_numeric($raw['server_id'] ?? null) && (int) $raw['server_id'] > 0 ? (int) $raw['server_id'] : null,
            'hostname' => is_string($note['hostname'] ?? null) ? $note['hostname'] : null,
            'email' => ! $old && is_string($raw['email'] ?? null) && $raw['email'] !== '' ? $raw['email'] : null,
            'totals' => array_map('intval', array_intersect_key(array_filter($totals, 'is_numeric'), self::MAXIMUMS)),
            'addons' => array_values(array_map('intval', array_filter($addons, 'is_numeric'))),
            'pool' => is_numeric($pool) && (int) $pool > 0 ? (int) $pool : null,
            'requested_at' => is_numeric($raw['requested_at'] ?? null) ? (int) $raw['requested_at'] : null,
        ];
    }

    /**
     * Save the note before the next step. The root password of a create about to be sent is kept
     * in it, encrypted, until the VPS is checked: a pending service never shows it, and a VPS found
     * after a create without a clear answer still gets it. A checked note keeps no password.
     * A made VPS's ID goes into the module data at once, so staff can always find it.
     *
     * @param  array<string, mixed>  $record
     * @return array{state: string, vpsid: string|null, server_id: int|null, hostname: string|null, email: string|null, totals: array<string, int>, addons: list<int>, pool: int|null, requested_at: int|null}
     */
    public static function remember(Service $service, array $record, ?string $password = null): array
    {
        $data = (array) $service->module_data;
        $note = Arr::only($record, ['state', 'vpsid', 'server_id', 'hostname', 'email', 'totals', 'addons', 'pool', 'requested_at']);
        $secret = $password !== null ? Crypt::encryptString($password) : (is_array($data[self::KEY] ?? null) ? ($data[self::KEY]['secret'] ?? null) : null);

        if (is_string($secret) && ($note['state'] ?? null) !== self::VERIFIED) {
            $note['secret'] = $secret;
        }

        $data[self::KEY] = $note;

        if (filled($record['vpsid'] ?? null)) {
            $data['vpsid'] = (string) $record['vpsid'];
        }

        $service->module_data = $data;
        $service->save();

        return self::record($service) ?? throw new RuntimeException(__('The add-on note of this VPS could not be saved.'));
    }

    /**
     * The root password kept in the note, or null when there is none.
     */
    public static function password(Service $service): ?string
    {
        $note = ((array) $service->module_data)[self::KEY] ?? null;
        $secret = is_array($note) ? ($note['secret'] ?? null) : null;

        if (! is_string($secret)) {
            return null;
        }

        try {
            return Crypt::decryptString($secret);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Drop the note: no VPS was made. A service still waiting keeps no root password either, also
     * not one an older version put on it before the request.
     */
    public static function forget(Service $service): void
    {
        $service->module_data = Arr::except((array) $service->module_data, self::KEY);

        if ($service->status === ServiceStatus::Pending) {
            $service->password = null;
        }

        $service->save();
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function object(mixed $value, string $name): array
    {
        self::ensure(is_array($value) && ($value === [] || ! array_is_list($value)), __('":name" must be a JSON object', ['name' => $name]));

        return $value;
    }

    private static function number(mixed $value, int $min, string $name): int
    {
        $number = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => 2147483647]]) : false;

        self::ensure($number !== false, __('":name" needs whole numbers of :min or more', ['name' => $name, 'min' => $min]));

        return $number;
    }

    private static function ensure(bool $condition, string $problem): void
    {
        self::check($condition, __('The resource add-on policy of this product is not valid: :problem. Fix it on the product; nothing was changed.', ['problem' => $problem]));
    }

    private static function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
