<?php

/*
 * Starts a client's plan change in a process of its own, for StockRaceTest on MySQL or MariaDB.
 * Once it holds its first service lock it prints "locked" and keeps its locks for a moment, so the
 * test can start another plan change at the same time. Then it prints one line of JSON: whether
 * the change started, its mode, how many tries it took, and the error class and code if it failed.
 *
 * Usage: php start-plan-change.php <service id> <product id> <pause in milliseconds>
 * The database settings come from the environment, as for the test itself.
 */

use App\Billing\PlanChanges;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$serviceId, $productId, $pause] = array_map('intval', array_slice($argv, 1, 3));
$tries = 0;
$paused = false;

// A lock this process waits for in vain fails within seconds, so the test never hangs.
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');

// The tries of the first transaction that is saved, not the ones inside it.
$saved = false;
Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$tries, &$saved): void {
    $tries += ! $saved && $event->connection->transactionLevel() === 1 ? 1 : 0;
});
Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$saved): void {
    $saved = $saved || $event->connection->transactionLevel() === 0;
});

DB::listen(function (QueryExecuted $query) use (&$paused, $pause): void {
    if (! $paused && $query->connection->transactionLevel() > 0 && preg_match('/\bfrom\s+[`"]?services\b.*\bfor update\b/is', $query->sql) === 1) {
        $paused = true;
        fwrite(STDOUT, "locked\n");
        fflush(STDOUT);
        usleep(max(0, $pause) * 1000);
    }
});

try {
    $change = app(PlanChanges::class)->start(
        Service::query()->with('product', 'client')->findOrFail($serviceId),
        Product::query()->findOrFail($productId),
    );
    $result = ['started' => true, 'mode' => $change->mode, 'tries' => $tries];
} catch (Throwable $exception) {
    // Only the kind of error and its code: a database error's message names the server and the query.
    $code = $exception instanceof PDOException ? ($exception->errorInfo[1] ?? $exception->getCode()) : $exception->getCode();
    $result = ['started' => false, 'error' => $exception::class, 'code' => $code, 'tries' => $tries];
}

fwrite(STDOUT, json_encode($result).PHP_EOL);
