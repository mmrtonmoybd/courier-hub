<?php

declare(strict_types=1);

use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use App\Enums\MatchKey;
use App\Models\WebhookDelivery;
use App\Models\WebhookTransfer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    forwardResponseStatus(200);
    Http::fake(fn () => Http::response(
        forwardResponseStatus() === 200 ? 'OK' : 'boom',
        forwardResponseStatus(),
    ));
});

it('follows the configured backoff schedule while a tenant stays down', function (): void {
    forwardResponseStatus(500);

    fakeTenant('shop_backoff', [['invoice_no' => 'INV-B1']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_backoff')->create([
        'target_url' => 'https://backoff.test',
    ]);

    $this->postJson('/api/webhooks/pathao', ['merchant_order_id' => 'INV-B1'])->assertStatus(202);

    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->attempts)->toBe(1)
        ->and($delivery->next_attempt_at->diffInMinutes(now(), absolute: true))->toBeLessThanOrEqual(1);

    foreach ([2 => 2, 3 => 5, 4 => 10] as $attempt => $expectedMinutes) {
        $delivery->update(['next_attempt_at' => now()->subMinute()]);

        $this->artisan('webhooks:retry')->assertSuccessful();

        $delivery->refresh();

        expect($delivery->attempts)->toBe($attempt)
            ->and($delivery->status)->toBe(DeliveryStatus::Failed)
            ->and(round($delivery->next_attempt_at->diffInMinutes(now(), absolute: true)))
            ->toBe((float) $expectedMinutes);
    }
});

it('delivers a failed webhook once the tenant recovers', function (): void {
    forwardResponseStatus(500);

    fakeTenant('shop_recover', [['invoice_no' => 'INV-R1']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_recover')->create([
        'target_url' => 'https://recover.test',
    ]);

    $this->postJson('/api/webhooks/pathao', ['merchant_order_id' => 'INV-R1'])->assertStatus(202);

    expect(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Failed);

    forwardResponseStatus(200);
    WebhookDelivery::query()->sole()->update(['next_attempt_at' => now()->subMinute()]);

    $this->artisan('webhooks:retry')->assertSuccessful();

    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->attempts)->toBe(2)
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->next_attempt_at)->toBeNull();
});

it('leaves a failed webhook alone until its next attempt is due', function (): void {
    WebhookDelivery::factory()->failed()->create([
        'next_attempt_at' => now()->addMinutes(5),
    ]);

    $this->artisan('webhooks:retry')->assertSuccessful();

    expect(WebhookDelivery::query()->sole()->attempts)->toBe(1);
    Http::assertNothingSent();
});

it('stops retrying a failed webhook after the attempt ceiling', function (): void {
    WebhookDelivery::factory()->failed()->create([
        'attempts' => (int) config('couriers.retry.max_failed_attempts'),
        'next_attempt_at' => now()->subMinute(),
    ]);

    $this->artisan('webhooks:retry')->assertSuccessful();

    Http::assertNothingSent();
});

it('promotes an unmatched webhook once the order appears in a tenant database', function (): void {
    fakeTenant('shop_late');

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_late')->create([
        'target_url' => 'https://late.test',
    ]);

    $this->postJson('/api/webhooks/pathao', ['merchant_order_id' => 'INV-LATE'])->assertStatus(202);

    expect(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Unmatched);
    Http::assertNothingSent();

    fakeTenant('shop_late', [['invoice_no' => 'INV-LATE']]);

    $this->travel(2)->minutes();

    $this->artisan('webhooks:retry')->assertSuccessful();

    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->database_name)->toBe('shop_late')
        ->and($delivery->matched_by)->toBe(MatchKey::Invoice->value);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://late.test/api/webhooks/pathao');
});

it('promotes an unmatched webhook to every site that now recognises it', function (): void {
    fakeTenant('shop_late_a');
    fakeTenant('shop_late_b');

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_late_a')->create([
        'target_url' => 'https://late-a.test',
    ]);
    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_late_b')->create([
        'target_url' => 'https://late-b.test',
    ]);

    $this->postJson('/api/webhooks/pathao', ['merchant_order_id' => 'INV-BOTH'])->assertStatus(202);

    fakeTenant('shop_late_a', [['invoice_no' => 'INV-BOTH']]);
    fakeTenant('shop_late_b', [['invoice_no' => 'INV-BOTH']]);

    $this->travel(2)->minutes();

    $this->artisan('webhooks:retry')->assertSuccessful();

    expect(WebhookDelivery::query()->count())->toBe(2)
        ->and(WebhookDelivery::query()->pluck('database_name')->all())
        ->toEqualCanonicalizing(['shop_late_a', 'shop_late_b']);
});

it('counts a failed re-resolution attempt and backs off', function (): void {
    fakeTenant('shop_never');

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_never')->create([
        'target_url' => 'https://never.test',
    ]);

    $this->postJson('/api/webhooks/pathao', ['merchant_order_id' => 'INV-NEVER'])->assertStatus(202);

    $this->travel(2)->minutes();

    $this->artisan('webhooks:retry')->assertSuccessful();

    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->status)->toBe(DeliveryStatus::Unmatched)
        ->and($delivery->attempts)->toBe(2);
});

it('gives up on an unmatched webhook older than the re-resolution window', function (): void {
    WebhookDelivery::factory()->unmatched()->create([
        'created_at' => now()->subMinutes((int) config('couriers.retry.unmatched.window_minutes') + 5),
        'next_attempt_at' => now()->subMinute(),
    ]);

    $this->artisan('webhooks:retry')->assertSuccessful();

    expect(WebhookDelivery::query()->sole()->attempts)->toBe(1);
});

it('prunes delivered rows sooner than unresolved ones', function (): void {
    WebhookDelivery::factory()->create([
        'created_at' => now()->subDays((int) config('couriers.retention.delivered_days') + 1),
    ]);
    WebhookDelivery::factory()->failed()->create([
        'created_at' => now()->subDays((int) config('couriers.retention.delivered_days') + 1),
    ]);

    $this->artisan('webhooks:prune')->assertSuccessful();

    expect(WebhookDelivery::query()->count())->toBe(1)
        ->and(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Failed);
});

it('registers the retry sweep on the scheduler', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command);

    expect($events->contains(fn (string $command): bool => str_contains($command, 'webhooks:retry')))->toBeTrue()
        ->and($events->contains(fn (string $command): bool => str_contains($command, 'webhooks:prune')))->toBeTrue();
});
