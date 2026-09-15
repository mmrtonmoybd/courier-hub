<?php

declare(strict_types=1);

use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use App\Filament\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use App\Filament\Resources\WebhookDeliveries\WebhookDeliveryResource;
use App\Filament\Resources\WebhookTransfers\Pages\CreateWebhookTransfer;
use App\Filament\Resources\WebhookTransfers\Pages\ListWebhookTransfers;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookTransfer;
use App\Services\TenantDatabaseCatalog;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    app()->instance(TenantDatabaseCatalog::class, new class extends TenantDatabaseCatalog
    {
        public function __construct() {}

        public function databases(): array
        {
            return ['zelobd', 'fashionmart'];
        }
    });
});

it('creates a transfer for a site address', function (): void {
    livewire(CreateWebhookTransfer::class)
        ->fillForm([
            'name' => 'Zelo BD',
            'target_url' => 'https://zelobd.com',
            'courier' => Courier::Pathao->value,
            'database_name' => 'zelobd',
            'priority' => 0,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas(WebhookTransfer::class, [
        'name' => 'Zelo BD',
        'target_url' => 'https://zelobd.com',
        'courier' => 'pathao',
        'database_name' => 'zelobd',
    ]);
});

it('rejects a target url that carries a path', function (string $url): void {
    livewire(CreateWebhookTransfer::class)
        ->fillForm([
            'name' => 'Bad Target',
            'target_url' => $url,
            'courier' => Courier::Pathao->value,
            'database_name' => 'zelobd',
        ])
        ->call('create')
        ->assertHasFormErrors(['target_url']);

    expect(WebhookTransfer::query()->count())->toBe(0);
})->with([
    'path' => 'https://zelobd.com/api/webhooks/pathao',
    'trailing path' => 'https://zelobd.com/shop',
    'query string' => 'https://zelobd.com?token=abc',
    'no scheme' => 'zelobd.com',
    'wrong scheme' => 'ftp://zelobd.com',
]);

it('accepts a bare host with a port and a trailing slash', function (string $url): void {
    livewire(CreateWebhookTransfer::class)
        ->fillForm([
            'name' => 'Local Site',
            'target_url' => $url,
            'courier' => Courier::Redx->value,
            'database_name' => 'zelobd',
        ])
        ->call('create')
        ->assertHasNoFormErrors();
})->with([
    'with port' => 'http://127.0.0.1:8000',
    'trailing slash' => 'https://zelobd.com/',
]);

it('maps one site to several couriers in a single step', function (): void {
    livewire(ListWebhookTransfers::class)
        ->callAction('onboardSite', [
            'name' => 'Fashion Mart',
            'target_url' => 'https://fashionmart.test',
            'database_name' => 'fashionmart',
            'couriers' => [
                ['courier' => Courier::Pathao->value, 'ack_secret' => 'pathao-secret'],
                ['courier' => Courier::Steadfast->value, 'ack_secret' => null],
            ],
        ]);

    expect(WebhookTransfer::query()->count())->toBe(2);

    assertDatabaseHas(WebhookTransfer::class, [
        'courier' => 'pathao',
        'database_name' => 'fashionmart',
        'ack_secret' => 'pathao-secret',
    ]);
    assertDatabaseHas(WebhookTransfer::class, [
        'courier' => 'steadfast',
        'database_name' => 'fashionmart',
        'ack_secret' => null,
    ]);
});

it('lists transfers and filters them by courier', function (): void {
    $pathao = WebhookTransfer::factory()->courier(Courier::Pathao)->create();
    $redx = WebhookTransfer::factory()->courier(Courier::Redx)->create();

    livewire(ListWebhookTransfers::class)
        ->assertCanSeeTableRecords([$pathao, $redx])
        ->filterTable('courier', Courier::Pathao->value)
        ->assertCanSeeTableRecords([$pathao])
        ->assertCanNotSeeTableRecords([$redx]);
});

it('retries a failed delivery from the log', function (): void {
    Http::preventStrayRequests();
    forwardResponseStatus(200);
    Http::fake(fn () => Http::response('OK', 200));

    $delivery = WebhookDelivery::factory()->failed()->create();

    livewire(ListWebhookDeliveries::class)
        ->callAction(TestAction::make('retry')->table($delivery))
        ->assertNotified();

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Delivered);
});

it('does not offer creating delivery log rows by hand', function (): void {
    expect(WebhookDeliveryResource::canCreate())->toBeFalse();
});
