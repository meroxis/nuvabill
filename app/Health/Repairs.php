<?php

namespace App\Health;

use App\Models\Admin;
use App\Models\ApiToken;
use App\Support\Activity;
use App\Support\EnvFile;
use App\Support\Settings;
use App\Support\SiteBackup;
use App\Updates\ReleaseSource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Number;
use RuntimeException;

/**
 * The safe repairs behind the "Fix it" buttons on the site health pages.
 *
 * The browser only says which check (and which of its rows) to fix; the paths, IDs and other
 * details always come from the check's own saved result, so a button can never be turned into
 * a repair of something else.
 */
class Repairs
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CoreFiles $coreFiles,
        private readonly DatabaseInspector $database,
        private readonly ReleaseSource $releases,
    ) {}

    /**
     * @param  array<string, mixed>  $fix  From the saved check result: {action, label, params?}.
     * @return string What was done, for the message staff see.
     */
    public function run(array $fix, Admin $admin): string
    {
        $params = (array) ($fix['params'] ?? []);
        $paths = array_values(array_filter((array) ($params['paths'] ?? []), 'is_string'));
        $ids = array_values(array_map('intval', (array) ($params['ids'] ?? [])));

        $message = match ((string) ($fix['action'] ?? '')) {
            'staff.require_two_factor' => $this->requireTwoFactor(),
            'staff.disable_inactive' => $this->disableStaff($ids, $admin),
            'api.delete_tokens' => $this->deleteTokens($ids),
            'files.fix_env' => $this->chmod(['.env'], 0600),
            'files.fix_folders' => $this->chmod($paths, 0755),
            'files.quarantine', 'core.quarantine' => trans_choice(':count file was moved to quarantine.|:count files were moved to quarantine.', $count = $this->coreFiles->quarantine($paths), ['count' => $count]),
            'core.accept' => trans_choice(':count file is marked as yours.|:count files are marked as yours.', $count = $this->coreFiles->accept($paths), ['count' => $count]),
            'core.restore' => trans_choice(':count original file was put back.|:count original files were put back.', $count = $this->coreFiles->restore($paths, $this->releases), ['count' => $count]),
            'settings.debug_off' => $this->writeEnv(['APP_DEBUG' => 'false'], __('Debug mode is off.')),
            'settings.secure_cookies' => $this->writeEnv(['SESSION_SECURE_COOKIE' => 'true'], __('Sign-in cookies are now only sent over HTTPS.')),
            'db.migrate' => $this->migrate(),
            'db.cleanup' => trans_choice(':count old record was removed.|:count old records were removed.', $count = array_sum($this->database->cleanUp()), ['count' => $count]),
            'db.optimize' => $this->optimize(),
            default => throw new RuntimeException(__('This problem cannot be fixed automatically.')),
        };

        Activity::log('health.fixed', 'Site health fix "'.($fix['action'] ?? '').'": '.$message, actor: $admin);

        return $message;
    }

    private function requireTwoFactor(): string
    {
        $this->settings->set('security.staff_two_factor', 'required');

        return __('Two-factor sign-in is now required for all staff. Staff without it set it up the next time they sign in.');
    }

    /**
     * @param  list<int>  $ids
     */
    private function disableStaff(array $ids, Admin $admin): string
    {
        // Never the person pressing the button, so nobody locks themselves out.
        $count = Admin::query()->whereIn('id', $ids)->whereKeyNot($admin->id)->where('is_active', true)->update(['is_active' => false]);

        return trans_choice(':count staff account was switched off.|:count staff accounts were switched off.', $count, ['count' => $count]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function deleteTokens(array $ids): string
    {
        $count = ApiToken::query()->whereIn('id', $ids)->delete();

        return trans_choice(':count API token was deleted.|:count API tokens were deleted.', $count, ['count' => $count]);
    }

    /**
     * @param  list<string>  $paths
     */
    private function chmod(array $paths, int $mode): string
    {
        $count = 0;

        foreach ($paths as $path) {
            $full = base_path($path);

            if (! $this->coreFiles->isSafePath($path) && $path !== '.env') {
                continue;
            }

            if (file_exists($full) && function_exists('posix_geteuid') && fileowner($full) === posix_geteuid() && @chmod($full, $mode)) {
                $count++;
            }
        }

        clearstatcache();

        if ($count === 0) {
            throw new RuntimeException(__('The permissions could not be changed. Ask your host, or change them in the file manager of your hosting panel.'));
        }

        return trans_choice('The permissions of :count item were changed to :mode.|The permissions of :count items were changed to :mode.', $count, ['count' => $count, 'mode' => sprintf('%o', $mode)]);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function writeEnv(array $values, string $done): string
    {
        (new EnvFile(base_path('.env')))->set($values);

        // A cached configuration would keep using the old values.
        if (app()->configurationIsCached()) {
            Artisan::call('config:cache');
        }

        return $done;
    }

    private function migrate(): string
    {
        app(SiteBackup::class)->create(SiteBackup::TYPE_DATABASE);
        Artisan::call('migrate', ['--force' => true]);

        return __('The missing database changes were made. A backup of the database was made first.');
    }

    private function optimize(): string
    {
        $result = $this->database->optimize();

        return __(':count tables were optimized and :size was freed. A backup of the database was made first.', [
            'count' => $result['tables'],
            'size' => Number::fileSize($result['freed']),
        ]);
    }
}
