<?php

declare(strict_types=1);

use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use App\Models\CourierSetting;
use App\Models\WebhookDelivery;
use App\Models\WebhookTransfer;
use App\Services\CourierIdentityExtractor;
use App\Services\TenantDatabaseCatalog;
use Database\Seeders\CourierSettingSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\WebhookTransferSeeder;

beforeEach(function (): void {
    app()->instance(TenantDatabaseCatalog::class, new class extends TenantDatabaseCatalog
    {
        public function __construct() {}

        public function databases(): array
        {
            return ['zelobd', 'fashionmart'];
        }
    });
});

it('seeds one setting per supported courier with its default secret', function (): void {
    $this->seed(CourierSettingSeeder::class);

    expect(CourierSetting::query()->count())->toBe(count(Courier::cases()));

    foreach (Courier::cases() as $courier) {
        $setting = CourierSetting::query()->where('courier', $courier->value)->sole();

        expect($setting->is_active)->toBeTrue()
            ->and($setting->secret)->toBe(config("couriers.{$courier->value}.ack.default_secret"));
    }
});

it('does not duplicate courier settings when seeded twice', function (): void {
    $this->seed(CourierSettingSeeder::class);
    $this->seed(CourierSettingSeeder::class);

    expect(CourierSetting::query()->count())->toBe(count(Courier::cases()));
});

it('maps every discovered tenant database to every courier', function (): void {
    $this->seed(WebhookTransferSeeder::class);

    expect(WebhookTransfer::query()->count())->toBe(2 * count(Courier::cases()))
        ->and(WebhookTransfer::query()->distinct()->pluck('database_name')->values()->all())
        ->toEqualCanonicalizing(['zelobd', 'fashionmart']);

    $transfer = WebhookTransfer::query()->where('database_name', 'zelobd')->first();

    expect($transfer->target_url)->toBe('https://zelobd.com')
        ->and($transfer->name)->toBe('Zelobd')
        ->and($transfer->is_active)->toBeTrue()
        ->and($transfer->inbound_token)->not->toBeEmpty();
});

it('gives an unknown database a placeholder address to edit', function (): void {
    app()->instance(TenantDatabaseCatalog::class, new class extends TenantDatabaseCatalog
    {
        public function __construct() {}

        public function databases(): array
        {
            return ['brand_new_shop'];
        }
    });

    $this->seed(WebhookTransferSeeder::class);

    expect(WebhookTransfer::query()->first()->target_url)->toBe('https://brand-new-shop.example.com');
});

it('is safe to re-run the transfer seeder', function (): void {
    $this->seed(WebhookTransferSeeder::class);
    $this->seed(WebhookTransferSeeder::class);

    expect(WebhookTransfer::query()->count())->toBe(2 * count(Courier::cases()));
});

it('builds demo traffic in every status across every route', function (): void {
    $this->seed(DemoDataSeeder::class);

    $deliveries = WebhookDelivery::query()->get();

    expect($deliveries)->not->toBeEmpty()
        ->and($deliveries->pluck('status')->unique()->map->value->values()->all())
        ->toEqualCanonicalizing([
            DeliveryStatus::Delivered->value,
            DeliveryStatus::Failed->value,
            DeliveryStatus::Unmatched->value,
        ])
        ->and($deliveries->pluck('courier')->unique())->toHaveCount(count(Courier::cases()))
        ->and($deliveries->pluck('database_name')->filter()->unique()->values()->all())
        ->toEqualCanonicalizing(['zelobd', 'fashionmart']);
});

it('builds demo rows that match the shape the relay produces', function (): void {
    $this->seed(DemoDataSeeder::class);

    WebhookDelivery::query()->where('status', DeliveryStatus::Delivered->value)->get()
        ->each(function (WebhookDelivery $delivery): void {
            expect($delivery->delivered_at)->not->toBeNull()
                ->and($delivery->response_status)->toBe(200)
                ->and($delivery->next_attempt_at)->toBeNull()
                ->and($delivery->target_url)->toBe($delivery->webhookTransfer->forwardUrl($delivery->forward_path))
                ->and($delivery->payload)->toBeArray()
                ->and($delivery->raw_body)->toBe(json_encode($delivery->payload));
        });

    WebhookDelivery::query()->where('status', DeliveryStatus::Failed->value)->get()
        ->each(function (WebhookDelivery $delivery): void {
            expect($delivery->next_attempt_at)->not->toBeNull()
                ->and($delivery->last_error)->not->toBeNull()
                ->and($delivery->delivered_at)->toBeNull();
        });

    WebhookDelivery::query()
        ->where('status', DeliveryStatus::Unmatched->value)
        ->whereNull('webhook_transfer_id')
        ->get()
        ->each(function (WebhookDelivery $delivery): void {
            expect($delivery->database_name)->toBeNull()
                ->and($delivery->target_url)->toBeNull()
                ->and($delivery->matched_by)->toBeNull();
        });
});

it('produces demo payloads the extractor can read back', function (): void {
    $this->seed(DemoDataSeeder::class);

    WebhookDelivery::query()->whereNotNull('webhook_transfer_id')->get()
        ->each(function (WebhookDelivery $delivery): void {
            $identity = app(CourierIdentityExtractor::class)
                ->extract($delivery->courier, (array) $delivery->payload);

            expect($identity->invoiceNo)->toBe($delivery->invoice_no)
                ->and($identity->trackingId)->toBe($delivery->tracking_id)
                ->and($identity->phone)->toBe($delivery->phone);
        });
});

it('spreads demo traffic over the last two weeks', function (): void {
    $this->seed(DemoDataSeeder::class);

    $days = WebhookDelivery::query()
        ->whereNotNull('webhook_transfer_id')
        ->get()
        ->map(fn (WebhookDelivery $delivery): string => $delivery->created_at->toDateString())
        ->unique();

    expect($days)->toHaveCount(14);
});
