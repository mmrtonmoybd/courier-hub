<?php

declare(strict_types=1);

use App\Enums\Courier;
use App\Filament\Widgets\AttentionNeededTable;
use App\Filament\Widgets\CourierBreakdownChart;
use App\Filament\Widgets\DeliveryVolumeChart;
use App\Filament\Widgets\RelayStatsOverview;
use App\Filament\Widgets\SitePerformanceTable;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookTransfer;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('renders the dashboard', function (): void {
    $this->get('/')->assertSuccessful();
});

it('reports the traffic of the last day in the stats', function (): void {
    $transfer = WebhookTransfer::factory()->courier(Courier::Pathao)->database('zelobd')->create();

    WebhookDelivery::factory()->count(3)->create(['webhook_transfer_id' => $transfer->getKey()]);
    WebhookDelivery::factory()->failed()->create(['webhook_transfer_id' => $transfer->getKey()]);
    WebhookDelivery::factory()->unmatched()->create();

    livewire(RelayStatsOverview::class)
        ->assertSee('Webhooks today')
        ->assertSee('Awaiting retry')
        ->assertSee('3 of 5')
        ->assertSee('60% forwarded successfully');
});

it('charts the daily volume for the selected window', function (): void {
    WebhookDelivery::factory()->count(2)->create();
    WebhookDelivery::factory()->failed()->create();

    livewire(DeliveryVolumeChart::class)
        ->assertSee('Forwarding volume')
        ->set('filter', '7')
        ->assertSuccessful();
});

it('charts the courier breakdown', function (): void {
    WebhookDelivery::factory()->create();

    livewire(CourierBreakdownChart::class)
        ->assertSee('Webhooks by courier')
        ->assertSuccessful();
});

it('lists site performance', function (): void {
    $transfer = WebhookTransfer::factory()->courier(Courier::Pathao)->database('zelobd')->create([
        'name' => 'Elegante Mart',
    ]);

    WebhookDelivery::factory()->count(2)->create(['webhook_transfer_id' => $transfer->getKey()]);

    livewire(SitePerformanceTable::class)
        ->assertCanSeeTableRecords([$transfer])
        ->assertSee('Elegante Mart');
});

it('offers a retry on the needs-attention list', function (): void {
    Http::preventStrayRequests();
    forwardResponseStatus(200);
    Http::fake(fn () => Http::response('OK', 200));

    $delivery = WebhookDelivery::factory()->failed()->create();

    livewire(AttentionNeededTable::class)
        ->assertCanSeeTableRecords([$delivery])
        ->callAction(TestAction::make('retry')->table($delivery))
        ->assertNotified();

    expect($delivery->refresh()->status->value)->toBe('delivered');
});

it('shows an empty state when nothing needs attention', function (): void {
    WebhookDelivery::factory()->create();

    livewire(AttentionNeededTable::class)
        ->assertSee('Nothing pending');
});
