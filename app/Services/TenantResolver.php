<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\TenantMatch;
use App\DataTransferObjects\WebhookIdentity;
use App\Enums\Courier;
use App\Enums\MatchKey;
use App\Models\WebhookTransfer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds which tenant databases own the order a courier webhook refers to.
 * Lookups are read-only: the relay never writes to a tenant database.
 *
 * With 10-30 tenants mapped to a courier, a sweep is one query per database
 * (all keys tested in a single statement) and the resolved route is cached, so
 * the follow-up events of the same parcel skip the sweep entirely.
 */
final class TenantResolver
{
    /**
     * How much each matched key says about ownership. A courier-assigned
     * tracking id outranks any number the merchant chose themselves.
     */
    private const STRENGTH_TRACKING = 4;

    private const STRENGTH_INVOICE = 2;

    private const STRENGTH_PHONE = 1;

    private const STRENGTH_NAME = 1;

    public function __construct(
        private readonly TenantConnectionManager $connections,
        private readonly TenantRouteCache $routeCache,
    ) {}

    /**
     * @return array<int, TenantMatch>
     */
    public function resolve(Courier $courier, WebhookIdentity $identity, bool $useCache = true): array
    {
        if ($identity->isEmpty()) {
            return [];
        }

        if ($useCache) {
            $cached = $this->routeCache->get($courier, $identity);

            if ($cached !== null) {
                return $cached;
            }
        }

        $candidates = [];

        foreach ($this->mappedDatabases($courier) as $database) {
            $candidate = $this->matchIn($database, $identity);

            if ($candidate !== null) {
                $candidates[] = $candidate + ['database' => $database];
            }
        }

        $matches = $this->strongestOnly($candidates);

        if ($matches !== []) {
            $this->routeCache->put($courier, $identity, $matches);
        }

        return $matches;
    }

    /**
     * @return array<int, string>
     */
    public function mappedDatabases(Courier $courier): array
    {
        return WebhookTransfer::query()
            ->where('courier', $courier->value)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->pluck('database_name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Sibling storefronts of one merchant reuse invoice numbers, and one
     * customer shops on several of them, so more than one database can
     * recognise the same parcel. Only the databases holding the strongest
     * evidence survive; everything weaker is a namesake, not an owner.
     *
     * @param  array<int, array{database: string, key: MatchKey, strength: int}>  $candidates
     * @return array<int, TenantMatch>
     */
    private function strongestOnly(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $strongest = max(array_column($candidates, 'strength'));

        $matches = [];

        foreach ($candidates as $candidate) {
            if ($candidate['strength'] === $strongest) {
                $matches[] = new TenantMatch($candidate['database'], $candidate['key']);
            }
        }

        return $matches;
    }

    /**
     * One statement per tenant tests every key at once; priority between the
     * keys is then applied in PHP (invoice, tracking, phone, and name only when
     * the payload carried none of the other three).
     *
     * The reported key keeps that order, but the strength score does not: a
     * tracking id is assigned by the courier itself and is unique across every
     * site, an invoice number is only unique within one site, and a phone
     * number is unique to nobody.
     *
     * @return array{key: MatchKey, strength: int}|null
     */
    private function matchIn(string $database, WebhookIdentity $identity): ?array
    {
        try {
            $row = $this->probeOrders($database, $identity);

            if ($row !== null) {
                $key = null;
                $strength = 0;

                if (filled($identity->invoiceNo) && (int) ($row['by_invoice'] ?? 0) === 1) {
                    $key = MatchKey::Invoice;
                    $strength += self::STRENGTH_INVOICE;
                }

                if (filled($identity->trackingId) && (int) ($row['by_tracking'] ?? 0) === 1) {
                    $key ??= MatchKey::Tracking;
                    $strength += self::STRENGTH_TRACKING;
                }

                if (filled($identity->phone) && (int) ($row['by_phone'] ?? 0) === 1) {
                    $key ??= MatchKey::Phone;
                    $strength += self::STRENGTH_PHONE;
                }

                if ($key instanceof MatchKey) {
                    return ['key' => $key, 'strength' => $strength];
                }
            }

            if (! $identity->hasStrongKey() && filled($identity->name) && $this->matchesName($database, $identity->name)) {
                return ['key' => MatchKey::Name, 'strength' => self::STRENGTH_NAME];
            }
        } catch (Throwable $exception) {
            Log::warning('Tenant lookup failed', [
                'database' => $database,
                'error' => $exception->getMessage(),
            ]);
        } finally {
            $this->connections->forget($database);
        }

        return null;
    }

    /**
     * @return array<string, int>|null
     */
    private function probeOrders(string $database, WebhookIdentity $identity): ?array
    {
        if (! $identity->hasStrongKey()) {
            return null;
        }

        $selects = [];
        $bindings = [];
        $conditions = [];

        if (filled($identity->invoiceNo)) {
            $selects[] = 'max(case when invoice_no = ? then 1 else 0 end) as by_invoice';
            $bindings[] = $identity->invoiceNo;
            $conditions[] = ['invoice_no = ?', [$identity->invoiceNo]];
        }

        if (filled($identity->trackingId)) {
            $selects[] = 'max(case when tracking_id = ? or tracking_id2 = ? or tracking_id3 = ? then 1 else 0 end) as by_tracking';
            array_push($bindings, $identity->trackingId, $identity->trackingId, $identity->trackingId);
            $conditions[] = [
                '(tracking_id = ? or tracking_id2 = ? or tracking_id3 = ?)',
                [$identity->trackingId, $identity->trackingId, $identity->trackingId],
            ];
        }

        $phoneDigits = filled($identity->phone) ? $this->lastTenDigits($identity->phone) : null;

        if ($phoneDigits !== null) {
            $selects[] = 'max(case when '.$this->normalizedPhoneExpression().' like ? then 1 else 0 end) as by_phone';
            $bindings[] = '%'.$phoneDigits;
            $conditions[] = [$this->normalizedPhoneExpression().' like ?', ['%'.$phoneDigits]];
        }

        if ($selects === []) {
            return null;
        }

        $where = [];

        foreach ($conditions as [$sql, $params]) {
            $where[] = $sql;

            foreach ($params as $param) {
                $bindings[] = $param;
            }
        }

        $sql = 'select '.implode(', ', $selects).' from orders where '.implode(' or ', $where);

        $result = $this->connections->connection($database)->selectOne($sql, $bindings);

        if ($result === null) {
            return null;
        }

        return array_map(static fn (mixed $value): int => (int) $value, (array) $result);
    }

    private function matchesName(string $database, string $name): bool
    {
        return $this->connections->connection($database)
            ->table('customers')
            ->where('name', $name)
            ->exists();
    }

    private function normalizedPhoneExpression(): string
    {
        return "replace(replace(replace(replace(customer_phone, ' ', ''), '-', ''), '+', ''), '(', '')";
    }

    /**
     * Phone numbers arrive in many shapes (+8801…, 8801…, 01…), so only the
     * subscriber part is compared.
     */
    private function lastTenDigits(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (mb_strlen($digits) < 10) {
            return null;
        }

        return mb_substr($digits, -10);
    }
}
