<?php

namespace App\Console\Commands;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Health\SiteHealth;
use App\Health\Status;
use App\Support\Locales;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nuvabill:security-check
    {--json : Print the result as JSON, for your own monitoring}
    {--quiet-if-healthy : Print nothing when there are no problems (for cron)}')]
#[Description('Run the site health check (security and database) and show what needs fixing. Exits with code 1 while an urgent issue is open.')]
class SecurityCheck extends Command
{
    public function handle(SiteHealth $health): int
    {
        $run = $health->run('cli');
        $checks = $run->checks();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'version' => config('nuvabill.version'),
                'checked_at' => $run->created_at->toIso8601String(),
                'scores' => ['security' => $run->security_score, 'database' => $run->database_score],
                'urgent' => $run->urgent_count,
                'warnings' => $run->warning_count,
                'passed' => $run->passed_count,
                'problems' => $checks->filter(fn (CheckResult $check): bool => $check->isProblem())->map(fn (CheckResult $check): array => [
                    'id' => $check->id,
                    'status' => $check->status->value,
                    'title' => Locales::inEnglish(fn (): string => $check->displayTitle()),
                    'summary' => Locales::inEnglish(fn (): string => $check->displaySummary()),
                ])->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $run->urgent_count > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($this->option('quiet-if-healthy') && $run->urgent_count === 0 && $run->warning_count === 0) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('  Security score <options=bold>'.($run->security_score ?? '—').'</> of 100 · Database score <options=bold>'.($run->database_score ?? '—').'</> of 100');
        $this->newLine();

        foreach ([CheckGroup::SECURITY, CheckGroup::DATABASE] as $section) {
            $problems = $run->checks($section)->filter(fn (CheckResult $check): bool => $check->isProblem())
                ->sortBy(fn (CheckResult $check): int => $check->status === Status::Urgent ? 0 : 1);

            foreach ($problems as $check) {
                $label = $check->status === Status::Urgent ? '<fg=red;options=bold>URGENT</>' : '<fg=yellow>FIX   </>';
                $summary = $check->summary !== '' ? ' — '.$check->displaySummary() : '';
                $this->line("  {$label}  {$check->displayTitle()}{$summary}");
            }
        }

        $ignored = $checks->filter(fn (CheckResult $check): bool => $check->ignored)->count();
        $this->newLine();
        $this->line("  <fg=green>{$run->passed_count} passed</>".($ignored ? " · {$ignored} ignored" : '').' · details: '.route('admin.health.index'));
        $this->newLine();

        return $run->urgent_count > 0 ? self::FAILURE : self::SUCCESS;
    }
}
