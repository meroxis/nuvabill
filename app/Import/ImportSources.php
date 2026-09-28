<?php

namespace App\Import;

use App\Import\Blesta\BlestaImporter;
use App\Import\Fossbilling\FossbillingImporter;
use App\Import\Paymenter\PaymenterImporter;
use App\Import\Whmcs\WhmcsImporter;
use InvalidArgumentException;

/**
 * The billing systems Nuvabill imports from, and the saved connection to one of them.
 */
final class ImportSources
{
    /**
     * @var array<string, class-string<ImportSource>>
     */
    public const SOURCES = [
        'whmcs' => WhmcsImporter::class,
        'blesta' => BlestaImporter::class,
        'fossbilling' => FossbillingImporter::class,
        'paymenter' => PaymenterImporter::class,
    ];

    /**
     * Source key => product name, for the source picker.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        return array_map(fn (string $class): string => $class::name(), self::SOURCES);
    }

    /**
     * @return class-string<ImportSource>
     */
    public static function get(string $key): string
    {
        return self::SOURCES[$key] ?? throw new InvalidArgumentException(__('Unknown system to import from: :name', ['name' => $key]));
    }

    /**
     * The saved database details, with the source they belong to. Before 0.4.9 only WHMCS could be
     * imported, and its details were kept in "import.whmcs".
     *
     * @return array{source?: string, host?: string, port?: int, database?: string, username?: string, password?: string, prefix?: string, key?: string}
     */
    public static function connection(): array
    {
        $connection = (array) setting('import.connection');

        if ($connection === [] && filled(((array) setting('import.whmcs'))['database'] ?? null)) {
            return ['source' => 'whmcs'] + (array) setting('import.whmcs');
        }

        return $connection;
    }

    public static function connect(array $connection): ImportSource
    {
        return self::get((string) ($connection['source'] ?? 'whmcs'))::connect($connection);
    }
}
