<?php

declare(strict_types=1);

use App\Enums\Courier;
use App\Models\CourierSetting;
use App\Models\WebhookDelivery;
use App\Models\WebhookTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    forwardResponseStatus(200);
    Http::fake(fn () => Http::response('OK', 200));
});

it('never writes to a tenant database', function (): void {
    fakeTenant('shop_readonly', [['invoice_no' => 'INV-RO', 'customer_phone' => '01711112222']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_readonly')->create([
        'target_url' => 'https://readonly.test',
    ]);

    $before = DB::connection('tenant_shop_readonly')->table('orders')->get()->toArray();

    $this->postJson('/api/webhooks/pathao', [
        'merchant_order_id' => 'INV-RO',
        'event' => 'order.delivered',
        'collected_amount' => 1500,
    ])->assertStatus(202);

    expect(DB::connection('tenant_shop_readonly')->table('orders')->get()->toArray())->toEqual($before);
});

it('prefers the site secret, then the courier secret, then the packaged default', function (): void {
    fakeTenant('shop_chain', [['invoice_no' => 'INV-CHAIN']]);

    $transfer = WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_chain')->create([
        'target_url' => 'https://chain.test',
        'ack_secret' => 'site-secret',
    ]);

    CourierSetting::factory()->create([
        'courier' => Courier::Pathao->value,
        'secret' => 'courier-secret',
    ]);

    $header = 'X-Pathao-Merchant-Webhook-Integration-Secret';
    $payload = ['merchant_order_id' => 'INV-CHAIN'];

    $this->postJson('/api/webhooks/pathao', $payload)->assertHeader($header, 'site-secret');

    $transfer->update(['ack_secret' => null]);

    $this->postJson('/api/webhooks/pathao', $payload)->assertHeader($header, 'courier-secret');

    CourierSetting::query()->where('courier', Courier::Pathao->value)->delete();

    $this->postJson('/api/webhooks/pathao', $payload)
        ->assertHeader($header, config('couriers.pathao.ack.default_secret'));
});

it('answers a courier integration handshake that carries no order', function (): void {
    fakeTenant('shop_handshake', [['invoice_no' => 'INV-X']]);

    WebhookTransfer::factory()->courier(Courier::Carrybee)->database('shop_handshake')->create([
        'target_url' => 'https://handshake.test',
    ]);

    $this->postJson('/api/webhooks/carrybee', ['event' => 'webhook.integration'])
        ->assertStatus(202)
        ->assertHeader('X-CB-Webhook-Integration-Header', config('couriers.carrybee.ack.default_secret'));

    Http::assertNothingSent();
});

it('stores the raw body and forwards it unchanged, even when it is not json', function (): void {
    fakeTenant('shop_raw', [['invoice_no' => 'INV-RAW']]);

    WebhookTransfer::factory()->courier(Courier::Steadfast)->database('shop_raw')->create([
        'target_url' => 'https://raw.test',
    ]);

    $this->call(
        'POST',
        '/api/webhooks/steadfast',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
        'invoice=INV-RAW&status=delivered',
    )->assertStatus(200);

    Http::assertSent(fn ($request): bool => $request->body() === 'invoice=INV-RAW&status=delivered');

    expect(WebhookDelivery::query()->sole())
        ->raw_body->toBe('invoice=INV-RAW&status=delivered')
        ->invoice_no->toBe('INV-RAW');
});
