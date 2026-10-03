<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Reads and writes system settings stored in the database. Values are cached and JSON encoded,
 * so booleans, numbers and arrays keep their type. Secret keys are encrypted at rest.
 */
class Settings
{
    private const CACHE_KEY = 'nuvabill.settings';

    /**
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'company.name' => 'My Hosting Company',
        'company.email' => 'billing@example.com',
        'company.address' => '',
        'company.phone' => '',
        'company.tax_id' => '',

        'billing.currency' => 'USD',
        'billing.invoice_prefix' => 'INV-',
        'billing.credit_note_prefix' => 'CN-',
        'billing.renewal_days_before' => 7,
        'billing.payment_terms_days' => 7,
        'billing.plan_changes' => true,
        // Payment gateway slugs in the order clients see them. Gateways not listed come last, by name.
        'billing.gateway_order' => [],
        // Knowledge base, announcements and the network status page. Servers are checked every 5 minutes.
        'knowledgebase.enabled' => true,
        'announcements.enabled' => true,
        'status.enabled' => true,
        'status.checks' => true,
        'status.alerts' => true,
        'billing.downgrade' => 'credit',
        // Automatic payments: renewals charged to cards and PayPal accounts clients saved.
        'billing.autopay' => true,
        'billing.autopay_days_before' => 0,
        'billing.autopay_notice_days' => 3,
        'billing.autopay_retry_days' => [3, 7],
        'billing.autopay_offer_save' => true,
        'billing.autopay_card_notice' => true,

        'currency.rates' => [],

        'tax.enabled' => false,
        'tax.inclusive' => false,
        'tax.domains' => true,
        'tax.id_label' => 'VAT number',

        'wallet.enabled' => true,
        'wallet.min_deposit' => 5,
        'wallet.max_deposit' => 1000,
        'wallet.auto_apply' => true,

        'quotes.prefix' => 'Q-',
        'quotes.valid_days' => 30,

        'affiliates.enabled' => false,
        'affiliates.percent' => 10,
        'affiliates.hold_days' => 30,
        'affiliates.cookie_days' => 60,
        'affiliates.recurring' => false,

        'license.white_label_key' => '',
        'license.white_label' => null,

        'locale.default' => 'en',
        'locale.enabled' => ['de', 'en', 'es', 'fr', 'pt_BR', 'tr', 'ru', 'ar', 'ckb', 'zh_CN'],

        'marketplace.developer_share' => 83,
        // Store only: old slug => new slug of renamed items (nuvabill:marketplace-rename).
        'marketplace.renamed_items' => [],
        // Store only: slugs developers cannot take, kept for official items that are not listed yet.
        'marketplace.reserved_slugs' => [],

        'automation.enabled' => true,
        'automation.reminder_days' => [1, 3, 7],
        'automation.suspend_days' => 3,
        'automation.terminate_days' => 0,
        'automation.last_run_at' => null,

        'domains.nameservers' => [],
        'domains.auto_register' => true,
        'domains.renewal_days_before' => 30,
        'domains.expiry_notice_days' => [30, 7],

        'fraud.enabled' => true,
        'fraud.block_disposable_email' => true,
        'fraud.max_orders_per_ip' => 3,
        'fraud.check_country' => true,

        'branding.accent' => '#0B7A70',
        'branding.icon' => null,

        'theme.active' => 'nova',
        'orderform.active' => 'standard',

        'orders.accept_terms_url' => '',
        // New orders still unpaid this many days after their invoice was due are cancelled, so the
        // stock and server room they hold go back on sale. 0 turns it off.
        'orders.cancel_unpaid_days' => 7,
        'company.privacy_url' => '',

        'mail.mailer' => 'log',
        'mail.host' => '',
        'mail.port' => 587,
        'mail.username' => '',
        'mail.password' => '',
        'mail.encryption' => 'tls',
        'mail.from_address' => 'billing@example.com',
        'mail.from_name' => 'My Hosting Company',

        'updates.channel' => 'stable',
        'updates.auto_security' => true,
        'updates.auto_all' => false,
        'updates.last_checked_at' => null,
        'updates.latest' => null,

        'import.whmcs' => null,
        'import.whmcs_status' => null,
        'import.connection' => null,
        'import.status' => null,
        'import.preview' => null,
        'import.password_keys' => [],

        'social.google' => null,
        'social.github' => null,
        'social.facebook' => null,

        'security.staff_two_factor' => 'optional',
        'security.client_two_factor' => 'optional',
        'security.client_two_factor_methods' => ['totp', 'email'],
        'security.captcha_provider' => 'off',
        'security.captcha_site_key' => '',
        'security.captcha_secret' => '',
        'security.captcha_forms' => ['client_login', 'client_register', 'password_reset'],
        'security.captcha_checked_key' => null,

        // Site health: the nightly check, its emails, and database upkeep.
        'health.nightly' => true,
        'health.outside_check' => true,
        'health.email_urgent' => true,
        'health.email_warnings' => false,
        'health.accepted_files' => [],
        'health.check_requested' => false,
        'database.cleanup_nightly' => false,
        'database.optimize_weekly' => false,
        'database.keep_activity_days' => 365,
        'database.keep_license_checks_days' => 90,
        'database.keep_jobs_days' => 30,
        'database.keep_health_days' => 90,
        'database.last_optimized_at' => null,
        // Automations: the hour timed triggers are checked each day, and the last day they were.
        'automations.scan_hour' => 9,
        'automations.last_scan' => null,

        // AI help: Claude with the owner's own Anthropic key. The limit is in US cents per month.
        'ai.key' => '',
        'ai.model' => 'claude-haiku-4-5',
        'ai.monthly_limit' => 2000,
        'ai.staff_language' => 'en',
        'ai.drafts' => true,
        'ai.translate' => true,
        'ai.summaries' => true,
        'ai.descriptions' => true,
        'ai.warned_month' => null,

        // Chat apps. Telegram: a bot the owner makes with @BotFather. WhatsApp: Meta's Cloud API,
        // connected by QR code through the Nuvabill connect page, or by hand with the owner's own Meta app.
        'chat.telegram_token' => '',
        'chat.telegram_bot' => '',
        'chat.telegram_secret' => '',
        'chat.telegram_staff_chat' => '',
        'chat.telegram_groups' => [],
        'chat.whatsapp_token' => '',
        'chat.whatsapp_waba' => '',
        'chat.whatsapp_phone_id' => '',
        'chat.whatsapp_number' => '',
        'chat.whatsapp_name' => '',
        'chat.whatsapp_via' => '',
        'chat.whatsapp_webhook_key' => '',
        'chat.whatsapp_app_secret' => '',
        'chat.whatsapp_templates' => [],
        'chat.whatsapp_tickets' => true,
        'chat.department' => null,
        'chat.events' => [
            'invoice.created' => ['telegram' => true, 'whatsapp' => false],
            'invoice.reminder' => ['telegram' => true, 'whatsapp' => true],
            'invoice.autopay_failed' => ['telegram' => true, 'whatsapp' => true],
            'invoice.payment_received' => ['telegram' => true, 'whatsapp' => false],
            'service.welcome' => ['telegram' => true, 'whatsapp' => true],
            'service.suspended' => ['telegram' => true, 'whatsapp' => true],
            'ticket.reply' => ['telegram' => true, 'whatsapp' => true],
            'domain.expiring' => ['telegram' => true, 'whatsapp' => true],
        ],

        // Search engines: what the store tells Google, Bing and the apps that show link previews.
        'seo.visible' => true,
        'seo.title_pattern' => '{page} · {company}',
        'seo.home_title' => '',
        'seo.home_description' => '',
        'seo.google_code' => '',
        'seo.bing_code' => '',
        'seo.sitemap' => true,
        'seo.structured_data' => true,
        'seo.language_links' => true,
        'seo.robots_extra' => '',
        'seo.share_image' => null,
        'backups.last_at' => null,
        'backups.last_type' => null,
        'backups.last_encrypted' => false,

        // Admin phone app: this site's key pair for push alerts, made on first use.
        'push.vapid_public' => '',
        'push.vapid_private' => '',
    ];

    /**
     * Keys stored encrypted in the database.
     *
     * @var list<string>
     */
    public const SECRET_KEYS = ['license.white_label_key', 'mail.password', 'import.whmcs', 'import.connection', 'import.password_keys', 'social.google', 'social.github', 'social.facebook', 'security.captcha_secret', 'ai.key', 'chat.telegram_token', 'chat.telegram_secret', 'chat.whatsapp_token', 'chat.whatsapp_app_secret', 'chat.whatsapp_webhook_key', 'push.vapid_private'];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->all();

        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $stored = json_encode($value, JSON_THROW_ON_ERROR);

            if (in_array($key, self::SECRET_KEYS, true) && $value !== null && $value !== '') {
                $stored = Crypt::encryptString($stored);
            }

            Setting::query()->updateOrCreate(['key' => $key], ['value' => $stored]);
        }

        $this->flush();
    }

    /**
     * All settings, with defaults filled in for keys never saved.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        if (! Installation::isInstalled()) {
            return $this->values = self::DEFAULTS;
        }

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn (): array => Setting::query()->pluck('value', 'key')->all());
        } catch (QueryException) {
            // The database is not migrated yet (fresh copy or first deploy): use the defaults for now.
            return self::DEFAULTS;
        }

        $decoded = [];

        foreach ($stored as $key => $raw) {
            $decoded[$key] = $this->decode($key, $raw);
        }

        return $this->values = array_merge(self::DEFAULTS, $decoded);
    }

    public function flush(): void
    {
        $this->values = null;
        Cache::forget(self::CACHE_KEY);
    }

    private function decode(string $key, ?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        if (in_array($key, self::SECRET_KEYS, true) && ! str_starts_with($raw, '"') && $raw !== 'null') {
            try {
                $raw = Crypt::decryptString($raw);
            } catch (\Throwable) {
                return null;
            }
        }

        return json_decode($raw, true);
    }
}
