<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site health used to save the database user's grant lines with MariaDB's sign-in part, which holds
 * the password hash ("IDENTIFIED VIA … USING '…'"). New checks leave it out; this removes it from the
 * results saved before, which are kept for months and copied into every database backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('health_runs')) {
            return;
        }

        DB::table('health_runs')
            ->select(['id', 'results'])
            ->where('results', 'like', '%db.scope%')
            ->where('results', 'like', '%IDENTIFIED%')
            ->chunkById(20, function ($runs): void {
                foreach ($runs as $run) {
                    $results = json_decode((string) $run->results, true);
                    $cleaned = is_array($results) ? $this->withoutCredentials($results) : null;
                    $json = $cleaned === null ? false : json_encode($cleaned);

                    // A result that cannot be read back cannot be cleaned, so it is removed instead.
                    if ($json === false) {
                        DB::table('health_runs')->where('id', $run->id)->delete();
                    } elseif ($cleaned !== $results) {
                        DB::table('health_runs')->where('id', $run->id)->update(['results' => $json]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Nothing to put back: the removed text was a password hash.
    }

    /**
     * The results with every db.scope line cut before its sign-in part, as
     * DatabaseSafetyChecks::withoutCredentials() does for new checks.
     *
     * @param  array<int|string, mixed>  $results
     * @return array<int|string, mixed>
     */
    private function withoutCredentials(array $results): array
    {
        foreach ($results as $index => $check) {
            if (! is_array($check) || ($check['id'] ?? null) !== 'db.scope' || ! is_array($check['items'] ?? null)) {
                continue;
            }

            foreach ($check['items'] as $key => $item) {
                if (is_array($item) && is_string($item['label'] ?? null)
                    && preg_match('/\sIDENTIFIED\s/i', $item['label'], $match, PREG_OFFSET_CAPTURE)) {
                    $results[$index]['items'][$key]['label'] = rtrim(substr($item['label'], 0, $match[0][1]));
                }
            }
        }

        return $results;
    }
};
