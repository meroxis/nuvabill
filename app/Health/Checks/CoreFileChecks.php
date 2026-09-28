<?php

namespace App\Health\Checks;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Health\CoreFiles;
use App\Models\MarketplaceInstall;

/**
 * Nuvabill's own code, and the code of installed marketplace packages, compared with what was
 * signed: changed files, and code planted where visitors can run it.
 */
class CoreFileChecks extends CheckGroup
{
    public function __construct(private readonly CoreFiles $files) {}

    public function key(): string
    {
        return 'core';
    }

    public function section(): string
    {
        return self::SECURITY;
    }

    public function title(): string
    {
        return 'Core files';
    }

    public function description(): string
    {
        return 'Whether anyone changed Nuvabill\'s own code or added code where visitors can run it.';
    }

    public function icon(): string
    {
        return 'code';
    }

    /**
     * @var array{checked: int, accepted: int, changed: list<array{path: string, modified: int|null}>, missing: list<string>, planted: list<array{path: string, modified: int|null, public: bool}>}|null
     */
    private ?array $comparison = null;

    public function run(): array
    {
        $this->comparison = null;

        return [$this->core(), $this->planted(), $this->packages()];
    }

    /**
     * @param  array<string, string>  $files
     * @return array{checked: int, accepted: int, changed: list<array{path: string, modified: int|null}>, missing: list<string>, planted: list<array{path: string, modified: int|null, public: bool}>}
     */
    private function comparison(array $files): array
    {
        return $this->comparison ??= $this->files->compare($files);
    }

    private function core(): CheckResult
    {
        $check = $this->check('core.files', 'Nuvabill files match the signed release', weight: 5);
        $manifest = $this->files->manifest();

        $skipped = match ($manifest['state']) {
            CoreFiles::STATE_MISSING, CoreFiles::STATE_UNSIGNED => 'This copy has no signed file list. Release downloads from 0.4.8 on include one.',
            CoreFiles::STATE_OTHER_VERSION => 'The file list is for :version; it is replaced by the next update.',
            default => null,
        };

        if ($skipped !== null) {
            return $check->skipped($skipped, ['version' => (string) $manifest['version']]);
        }

        if ($manifest['state'] === CoreFiles::STATE_BAD_SIGNATURE) {
            return $check->urgent('The list of Nuvabill\'s files was changed',
                advice: 'The file list does not match its signature, so someone may have edited it to hide changes. Reinstall this version from a fresh download.',
            );
        }

        $result = $this->comparison($manifest['files']);
        $changed = $result['changed'];

        if ($changed === [] && $result['missing'] === []) {
            return $check->passed(':count files checked', ['count' => number_format($result['checked'])]);
        }

        $items = [
            ...array_map(fn (array $file): array => [
                'label' => $file['path'],
                'value' => __('Different from the release').($file['modified'] ? ' · '.date('d M Y, H:i', $file['modified']) : ''),
                'mono' => true,
                'status' => 'warning',
                'fix' => $this->fix('core.restore', 'Put back the original', ['paths' => [$file['path']]], confirm: 'The release copy of this file is downloaded, checked and put back. The changed copy is kept in storage/app/quarantine.'),
            ], $changed),
            ...array_map(fn (string $path): array => ['label' => $path, 'value' => __('Missing'), 'mono' => true, 'status' => 'warning'], $result['missing']),
        ];

        return $check->warning(':count files differ from the signed :version release', ['count' => count($items), 'version' => $manifest['version']],
            advice: 'A changed file can be your own edit, or a sign of a break-in. If you made the change, mark it as yours and the check stops warning until the file changes again.',
            items: $items,
            fix: $changed !== [] ? $this->fix('core.accept', 'Mark as mine', ['paths' => array_column($changed, 'path')]) : null,
        );
    }

    private function planted(): CheckResult
    {
        $check = $this->check('core.planted', 'No unknown code where visitors can run it', weight: 5);
        $manifest = $this->files->manifest();

        if ($manifest['state'] !== CoreFiles::STATE_OK) {
            return $check->skipped('This needs the signed file list that comes with release downloads of Nuvabill.');
        }

        $planted = $this->comparison($manifest['files'])['planted'];

        if ($planted === []) {
            return $check->passed();
        }

        $public = array_values(array_filter($planted, fn (array $file): bool => $file['public']));

        return $check->failed($public !== [], ':count files are not part of Nuvabill', ['count' => count($planted)],
            advice: 'A program file that is not part of Nuvabill, in a folder visitors can reach, is a common sign of a break-in. If you do not know it, move it to quarantine and change your passwords.',
            items: array_map(fn (array $file): array => [
                'label' => $file['path'],
                'value' => ($file['public'] ? __('In the public folder') : __('In the Nuvabill folder')).($file['modified'] ? ' · '.date('d M Y, H:i', $file['modified']) : ''),
                'mono' => true,
                'status' => $file['public'] ? 'urgent' : 'warning',
                'fix' => $this->fix('core.quarantine', 'Move to quarantine', ['paths' => [$file['path']]], confirm: 'The file is moved to storage/app/quarantine, where nobody can run it. Nothing is deleted.', danger: true),
            ], $planted),
            fix: $this->fix('core.accept', 'Mark as mine', ['paths' => array_column($planted, 'path')]),
        );
    }

    private function packages(): CheckResult
    {
        $check = $this->check('core.packages', 'Extension and theme files match what was installed');
        $installs = MarketplaceInstall::query()->orderBy('name')->get();
        $known = $installs->filter(fn (MarketplaceInstall $install): bool => is_array($install->file_hashes) && $install->file_hashes !== []);

        if ($installs->isEmpty()) {
            return $check->passed('No marketplace packages installed');
        }

        if ($known->isEmpty()) {
            return $check->skipped('Packages installed before 0.4.8 are checked after their next update.');
        }

        $changed = [];

        foreach ($known as $install) {
            $now = MarketplaceInstall::fingerprint($install->type->directory($install->slug));

            foreach ($install->file_hashes as $path => $hash) {
                if (($now[$path] ?? null) !== $hash) {
                    $changed[] = ['label' => $install->name, 'value' => $path, 'mono' => true, 'status' => 'warning'];
                }
            }

            foreach (array_diff_key($now, $install->file_hashes) as $path => $hash) {
                $changed[] = ['label' => $install->name, 'value' => __('Added: :file', ['file' => $path]), 'mono' => true, 'status' => 'warning'];
            }
        }

        if ($changed === []) {
            return $check->passed(':count packages match what was signed', ['count' => $known->count()]);
        }

        return $check->warning(':count files of marketplace packages were changed', ['count' => count($changed)],
            advice: 'Installing the package again from the marketplace puts back the signed files.',
            items: array_slice($changed, 0, 100),
            link: $this->link('admin.marketplace.index', 'Open the marketplace'),
        );
    }
}
