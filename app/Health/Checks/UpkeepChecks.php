<?php

namespace App\Health\Checks;

use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Models\ActivityLog;
use App\Updates\UpdateManager;
use Illuminate\Support\Carbon;

/**
 * Keeping the site maintained: updates, the nightly cron job, backups and email sending.
 */
class UpkeepChecks extends CheckGroup
{
    public function __construct(
        private readonly UpdateManager $updates,
        private readonly ExtensionManager $extensions,
    ) {}

    public function key(): string
    {
        return 'upkeep';
    }

    public function section(): string
    {
        return self::SECURITY;
    }

    public function title(): string
    {
        return 'Updates, backups and email';
    }

    public function description(): string
    {
        return 'Updates, the nightly cron job, backups and email sending.';
    }

    public function icon(): string
    {
        return 'refresh';
    }

    public function run(): array
    {
        return [
            $this->version(),
            $this->securityUpdates(),
            $this->cron(),
            $this->recentBackup(),
            $this->safeBackups(),
            $this->mail(),
            $this->mailDomain(),
        ];
    }

    private function version(): CheckResult
    {
        $check = $this->check('upkeep.version', 'Nuvabill is up to date', weight: 5);
        $release = $this->updates->available();
        $checked = setting('updates.last_checked_at');

        if ($release !== null) {
            return $check->failed($release->isSecurity, 'Nuvabill :version is available (you have :current)', ['version' => $release->version, 'current' => $this->updates->currentVersion()],
                advice: $release->isSecurity ? 'This update fixes a security problem. Install it today.' : 'Updates fix problems and security issues. They are signed, and your site is backed up first.',
                link: $this->link('admin.updates.index', 'Open updates'),
            );
        }

        if ($checked === null || Carbon::parse($checked)->lt(now()->subDays(3))) {
            return $check->warning('Nuvabill has not checked for updates for a while',
                advice: 'The daily check needs the cron job and a connection to GitHub. Press "Check now" on the updates page to see what goes wrong.',
                link: $this->link('admin.updates.index', 'Open updates'),
            );
        }

        return $check->passed(':version', ['version' => $this->updates->currentVersion()]);
    }

    private function securityUpdates(): CheckResult
    {
        $check = $this->check('upkeep.auto_security', 'Security updates install by themselves');

        if (setting('updates.auto_security') || setting('updates.auto_all')) {
            return $check->passed();
        }

        return $check->warning('Security updates wait for someone to install them',
            advice: 'Security fixes matter most in the first days after they are published. Let them install at night.',
            link: $this->link('admin.updates.index', 'Open updates'),
        );
    }

    private function cron(): CheckResult
    {
        $check = $this->check('upkeep.cron', 'The nightly cron job ran', weight: 5);

        if (! setting('automation.enabled')) {
            return $check->skipped('Automation is turned off in Settings → Automation.');
        }

        $lastRun = setting('automation.last_run_at');

        if ($lastRun !== null && Carbon::parse($lastRun)->gte(now()->subHours(26))) {
            return $check->passed(':time', ['time' => Carbon::parse($lastRun)->diffForHumans()]);
        }

        return $check->urgent($lastRun === null ? 'It has never run' : 'It last ran :time', ['time' => $lastRun ? Carbon::parse($lastRun)->diffForHumans() : ''],
            advice: 'Without it there are no renewal invoices, reminders, suspensions, updates or security checks. Add the cron job from the install guide.',
            link: $this->link('admin.settings.edit', 'How to set it up'),
        );
    }

    private function recentBackup(): CheckResult
    {
        $check = $this->check('upkeep.backup', 'There is a backup from the last 7 days');
        $last = setting('backups.last_at');

        if ($last !== null && Carbon::parse($last)->gte(now()->subDays(7))) {
            return $check->passed('Last one :time', ['time' => Carbon::parse($last)->diffForHumans()]);
        }

        return $check->warning($last === null ? 'Nuvabill has not made a backup yet' : 'The last backup is from :time', ['time' => $last ? Carbon::parse($last)->diffForHumans() : ''],
            advice: 'Keep at least one backup a week, away from this server. The free Google Drive backup add-on does this on a schedule, or run "php artisan nuvabill:backup". Backups made by your hosting panel do not show here.',
            link: $this->link('admin.marketplace.index', 'Open the marketplace'),
        );
    }

    private function safeBackups(): CheckResult
    {
        $check = $this->check('upkeep.backup_safe', 'Backups are encrypted and kept off this server', weight: 1);
        $addons = $this->extensions->ofType(ExtensionManifest::TYPE_ADDON)
            ->filter(fn (ExtensionManifest $manifest): bool => in_array('backups', $manifest->permissions, true) && $this->extensions->isEnabled($manifest->slug))
            ->values();
        $offsite = $addons->pluck('name');

        if ($offsite->isEmpty()) {
            return $check->warning('No backup add-on copies your backups to another place',
                advice: 'A backup on the same server is lost together with the server. Install a backup add-on such as Google Drive backup.',
                link: $this->link('admin.marketplace.index', 'Open the marketplace'),
            );
        }

        // An add-on that is on but cannot upload (for example its Google access ran out) keeps no copy anywhere.
        $copied = $this->lastOffsiteCopy();

        if ($copied === null || $copied->lt(now()->subDays(7))) {
            return $check->warning(':names has not copied a backup off this server in the last 7 days', ['names' => $offsite->implode(', ')],
                advice: 'The add-on is on, but no upload worked. Open its settings to see the last error, and connect it again if needed.',
                link: $this->link('admin.marketplace.settings', 'Open the add-on settings', ['slug' => $addons->first()->slug]),
            );
        }

        $encrypted = setting('backups.last_offsite_at') !== null ? setting('backups.last_offsite_encrypted') : setting('backups.last_encrypted');

        if (! $encrypted) {
            return $check->warning('The last backup was not encrypted',
                advice: 'A backup holds every client and the .env file. Set a backup password so nobody else can open it.',
            );
        }

        return $check->passed(':names', ['names' => $offsite->implode(', ')]);
    }

    /**
     * When a backup add-on last stored a copy away from this server. Add-ons note it with
     * SiteBackup::record(); older ones only write "backup.uploaded" to the activity log.
     */
    private function lastOffsiteCopy(): ?Carbon
    {
        $recorded = setting('backups.last_offsite_at');
        $logged = ActivityLog::query()->where('action', 'backup.uploaded')->max('created_at');

        return collect([
            is_string($recorded) && $recorded !== '' ? Carbon::parse($recorded) : null,
            filled($logged) ? Carbon::parse($logged) : null,
        ])->filter()->max();
    }

    private function mail(): CheckResult
    {
        $check = $this->check('upkeep.mail', 'Email sending is set up', weight: 5);
        $mailer = (string) setting('mail.mailer');

        if (in_array($mailer, ['log', 'array'], true)) {
            return $check->urgent('Emails are written to a log file instead of being sent',
                advice: 'Clients get no invoices or password reset emails, and staff get no security alerts. Set up email sending.',
                link: $this->link('admin.settings.mail', 'Open email settings'),
            );
        }

        if ($mailer === 'smtp' && blank(setting('mail.host'))) {
            return $check->urgent('The SMTP server is empty', link: $this->link('admin.settings.mail', 'Open email settings'));
        }

        return $check->passed(':mailer', ['mailer' => strtoupper($mailer)]);
    }

    private function mailDomain(): CheckResult
    {
        $check = $this->check('upkeep.mail_dns', 'Your sending domain has SPF and DMARC', weight: 1);
        $from = (string) setting('mail.from_address');
        $domain = strtolower((string) substr(strrchr($from, '@') ?: '', 1));

        if ($domain === '' || preg_match('/(^|\.)(example\.(com|net|org)|test|local|localhost|invalid)$/', $domain)) {
            return $check->skipped('Set your own "from" address first.');
        }

        if (! function_exists('dns_get_record')) {
            return $check->skipped('This server cannot look up DNS records.');
        }

        $txt = fn (string $name): array => array_map(fn (array $record): string => strtolower((string) ($record['txt'] ?? '')), @dns_get_record($name, DNS_TXT) ?: []);
        $missing = array_keys(array_filter([
            'SPF' => ! collect($txt($domain))->contains(fn (string $record): bool => str_starts_with($record, 'v=spf1')),
            'DMARC' => ! collect($txt('_dmarc.'.$domain))->contains(fn (string $record): bool => str_starts_with($record, 'v=dmarc1')),
        ]));

        if ($missing === []) {
            return $check->passed(':domain', ['domain' => $domain]);
        }

        return $check->warning(':domain has no :records record', ['domain' => $domain, 'records' => implode(' or ', $missing)],
            advice: 'Without them, invoices and reminders often land in spam, and others can send email that looks like it comes from you. Your email or DNS provider shows the records to add.',
        );
    }
}
