<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opens read-only connections to tenant databases at runtime. Tenant
 * connections are never pre-declared in `config/database.php`: the relay's own
 * MySQL credentials are cloned and only the database name is overridden, so a
 * new tenant is a data change rather than a code change.
 */
final class TenantConnectionManager
{
    private const SYSTEM_DATABASES = [
        'information_schema',
        'mysql',
        'performance_schema',
        'sys',
    ];

    public function connectionName(string $database): string
    {
        return 'tenant_'.$database;
    }

    public function connection(string $database): Connection
    {
        $name = $this->connectionName($database);

        if (! Config::has("database.connections.{$name}")) {
            $base = (array) Config::get('database.connections.mysql', []);
            $base['database'] = $database;
            unset($base['name']);

            Config::set("database.connections.{$name}", $base);
        }

        return DB::connection($name);
    }

    public function forget(string $database): void
    {
        DB::purge($this->connectionName($database));
    }

    /**
     * Databases reachable on the shared credentials that hold an `orders`
     * table, i.e. the ones that can own a courier order.
     *
     * @return array<int, string>
     */
    public function availableTenantDatabases(): array
    {
        try {
            $rows = DB::connection('mysql')->select(
                'select table_schema as database_name from information_schema.tables where table_name = ? group by table_schema order by table_schema',
                ['orders'],
            );
        } catch (Throwable $exception) {
            Log::warning('Unable to list tenant databases', ['error' => $exception->getMessage()]);

            return [];
        }

        $databases = [];

        foreach ($rows as $row) {
            $database = (string) ($row->database_name ?? '');

            if ($database === '' || in_array($database, self::SYSTEM_DATABASES, true)) {
                continue;
            }

            $databases[] = $database;
        }

        return $databases;
    }
}
