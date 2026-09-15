<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * The HTTP status the faked tenant answers a forward with. Held in one place so
 * a test can make the tenant fail without fighting stub ordering.
 */
function forwardResponseStatus(?int $status = null): int
{
    static $current = 200;

    if ($status !== null) {
        $current = $status;
    }

    return $current;
}

/**
 * Registers a fake tenant database as a file-backed sqlite connection under the
 * name the relay would use for a real MySQL tenant, and creates the minimal
 * `orders`/`customers` shape the resolver reads.
 *
 * File-backed rather than in-memory because the resolver purges the connection
 * after every lookup.
 *
 * @param  array<int, array<string, mixed>>  $orders
 * @param  array<int, array<string, mixed>>  $customers
 */
function fakeTenant(string $database, array $orders = [], array $customers = []): void
{
    $path = storage_path("framework/testing/tenant-{$database}.sqlite");

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    if (file_exists($path)) {
        unlink($path);
    }

    touch($path);

    Config::set('database.connections.tenant_'.$database, [
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    $schema = DB::connection('tenant_'.$database)->getSchemaBuilder();

    $schema->dropIfExists('orders');
    $schema->create('orders', function ($table): void {
        $table->increments('id');
        $table->string('invoice_no')->nullable();
        $table->string('customer_phone')->nullable();
        $table->string('tracking_id')->nullable();
        $table->string('tracking_id2')->nullable();
        $table->string('tracking_id3')->nullable();
    });

    $schema->dropIfExists('customers');
    $schema->create('customers', function ($table): void {
        $table->increments('id');
        $table->string('name')->nullable();
    });

    if ($orders !== []) {
        DB::connection('tenant_'.$database)->table('orders')->insert($orders);
    }

    if ($customers !== []) {
        DB::connection('tenant_'.$database)->table('customers')->insert($customers);
    }
}
