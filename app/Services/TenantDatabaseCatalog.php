<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Lists the tenant databases an admin can choose from. Extracted from the
 * connection manager so it can be swapped in tests, where there is no MySQL
 * server to interrogate.
 */
class TenantDatabaseCatalog
{
    public function __construct(private readonly TenantConnectionManager $connections) {}

    /**
     * @return array<int, string>
     */
    public function databases(): array
    {
        return $this->connections->availableTenantDatabases();
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $databases = $this->databases();

        return array_combine($databases, $databases) ?: [];
    }
}
