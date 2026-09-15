<?php

declare(strict_types=1);

use App\DataTransferObjects\WebhookIdentity;
use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use App\Models\WebhookTransfer;
use App\Services\TenantResolver;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    forwardResponseStatus(200);
    Http::fake(fn () => Http::response('OK', 200));
});

it('finds the owning site among thirty mapped tenants', function (): void {
    foreach (range(1, 30) as $index) {
        $database = "site_{$index}";
        fakeTenant($database, $index === 17 ? [['invoice_no' => 'INV-NEEDLE']] : []);

        WebhookTransfer::factory()->courier(Courier::Pathao)->database($database)->create([
            'target_url' => "https://site-{$index}.test",
        ]);
    }

    $this->postJson('/api/webhooks/pathao', ['merchant_order_id' => 'INV-NEEDLE'])->assertStatus(202);

    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->database_name)->toBe('site_17')
        ->and($delivery->target_url)->toBe('https://site-17.test/api/webhooks/pathao');
});

it('caches a resolved route so the follow-up events of a parcel skip the sweep', function (): void {
    fakeTenant('shop_cached', [['invoice_no' => 'INV-CACHE']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_cached')->create([
        'target_url' => 'https://cached.test',
    ]);

    $identity = new WebhookIdentity(invoiceNo: 'INV-CACHE');

    expect(app(TenantResolver::class)->resolve(Courier::Pathao, $identity))->toHaveCount(1);

    // The order disappears, but the cached route still answers.
    fakeTenant('shop_cached');

    expect(app(TenantResolver::class)->resolve(Courier::Pathao, $identity))->toHaveCount(1)
        ->and(app(TenantResolver::class)->resolve(Courier::Pathao, $identity, useCache: false))->toHaveCount(0);
});

it('invalidates every cached route when a transfer is edited', function (): void {
    fakeTenant('shop_flush', [['invoice_no' => 'INV-FLUSH']]);

    $transfer = WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_flush')->create([
        'target_url' => 'https://flush.test',
    ]);

    $identity = new WebhookIdentity(invoiceNo: 'INV-FLUSH');

    expect(app(TenantResolver::class)->resolve(Courier::Pathao, $identity))->toHaveCount(1);

    fakeTenant('shop_flush');
    $transfer->update(['priority' => 5]);

    expect(app(TenantResolver::class)->resolve(Courier::Pathao, $identity))->toHaveCount(0);
});

it('keeps working when one tenant database is unreachable', function (): void {
    fakeTenant('shop_up', [['invoice_no' => 'INV-MIXED']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_up')->create([
        'target_url' => 'https://up.test',
    ]);
    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_missing')->create([
        'target_url' => 'https://missing.test',
    ]);

    config()->set('database.connections.tenant_shop_missing', [
        'driver' => 'sqlite',
        'database' => '/nonexistent/path/does-not-exist.sqlite',
        'prefix' => '',
    ]);

    $this->postJson('/api/webhooks/pathao', ['merchant_order_id' => 'INV-MIXED'])->assertStatus(202);

    expect(WebhookDelivery::query()->sole()->database_name)->toBe('shop_up');
});
