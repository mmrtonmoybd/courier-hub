<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Courier;
use App\Models\WebhookTransfer;
use App\Services\TenantDatabaseCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Maps every tenant database found on the shared credentials to every courier.
 * Run it once after pointing the relay at a MySQL server that already hosts the
 * ecommerce sites; the rows are then edited in the admin.
 */
final class WebhookTransferSeeder extends Seeder
{
    /**
     * @var array<string, string> tenant database => site address
     */
    private const KNOWN_SITES = [
        'zelobd' => 'https://zelobd.com',
        'fastclic_zelobd_ecom' => 'https://fastclic.com.bd',
        'fashionmart' => 'https://fashionmart.com.bd',
        'shopfashion' => 'https://shopfashion.com.bd',
        'grasenglow' => 'https://graceandglow.com.bd',
        'ric_solution' => 'https://ricsolution.com.bd',
        'dealer' => 'https://dealer.com.bd',
    ];

    public function run(): void
    {
        foreach ($this->sites() as $database => $url) {
            foreach (Courier::cases() as $courier) {
                WebhookTransfer::query()->updateOrCreate(
                    [
                        'courier' => $courier->value,
                        'database_name' => $database,
                        'target_url' => $url,
                    ],
                    [
                        'name' => $this->siteName($database),
                        'is_active' => true,
                        'priority' => 0,
                    ],
                );
            }
        }
    }

    /**
     * Databases actually present on the server win; the known list fills in the
     * site addresses, and anything unknown gets a placeholder to edit.
     *
     * @return array<string, string>
     */
    private function sites(): array
    {
        $databases = app(TenantDatabaseCatalog::class)->databases();

        if ($databases === []) {
            return self::KNOWN_SITES;
        }

        $sites = [];

        foreach ($databases as $database) {
            $sites[$database] = self::KNOWN_SITES[$database]
                ?? 'https://'.str_replace('_', '-', $database).'.example.com';
        }

        return $sites;
    }

    private function siteName(string $database): string
    {
        return (string) Str::of($database)->replace('_', ' ')->title();
    }
}
