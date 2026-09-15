<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\TenantMatch;
use App\DataTransferObjects\WebhookIdentity;
use App\Enums\Courier;
use App\Enums\MatchKey;
use Illuminate\Support\Facades\Cache;

/**
 * Couriers fire several events per parcel. Caching the tenant a parcel resolved
 * to keeps the 10-30 database sweep to once per parcel. The cache is versioned,
 * so editing any routing row invalidates every entry at once.
 */
final class TenantRouteCache
{
    private const VERSION_KEY = 'tenant-route:version';

    public function __construct(private readonly int $ttlMinutes = 1440) {}

    /**
     * @return array<int, TenantMatch>|null
     */
    public function get(Courier $courier, WebhookIdentity $identity): ?array
    {
        $key = $this->key($courier, $identity);

        if ($key === null) {
            return null;
        }

        /** @var array<int, array{database: string, matched_by: string}>|null $cached */
        $cached = Cache::get($key);

        if (! is_array($cached) || $cached === []) {
            return null;
        }

        $matches = [];

        foreach ($cached as $entry) {
            $matchedBy = MatchKey::tryFrom((string) ($entry['matched_by'] ?? ''));

            if ($matchedBy === null || blank($entry['database'] ?? null)) {
                return null;
            }

            $matches[] = new TenantMatch((string) $entry['database'], $matchedBy);
        }

        return $matches;
    }

    /**
     * @param  array<int, TenantMatch>  $matches
     */
    public function put(Courier $courier, WebhookIdentity $identity, array $matches): void
    {
        $key = $this->key($courier, $identity);

        if ($key === null || $matches === []) {
            return;
        }

        Cache::put(
            $key,
            array_map(
                static fn (TenantMatch $match): array => [
                    'database' => $match->databaseName,
                    'matched_by' => $match->matchedBy->value,
                ],
                $matches,
            ),
            now()->addMinutes($this->ttlMinutes),
        );
    }

    /**
     * Bumping the version orphans every cached route in one write.
     */
    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, $this->version() + 1);
    }

    private function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    private function key(Courier $courier, WebhookIdentity $identity): ?string
    {
        $fingerprint = implode('|', [
            $identity->invoiceNo ?? '',
            $identity->trackingId ?? '',
            $identity->phone ?? '',
        ]);

        if (trim($fingerprint, '|') === '') {
            return null;
        }

        return 'tenant-route:v'.$this->version().':'.$courier->value.':'.sha1($fingerprint);
    }
}
