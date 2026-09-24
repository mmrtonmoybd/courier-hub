<?php

declare(strict_types=1);

use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use App\Enums\MatchKey;
use App\Models\CourierSetting;
use App\Models\WebhookDelivery;
use App\Models\WebhookTransfer;
use Database\Seeders\CourierSettingSeeder;
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

it('forwards a webhook to the site whose database holds the invoice', function (): void {
    fakeTenant('shop_one', [['invoice_no' => 'INV-1001', 'customer_phone' => '01711112222']]);

    $transfer = WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_one')->create([
        'target_url' => 'https://shop-one.test',
    ]);

    $payload = ['merchant_order_id' => 'INV-1001', 'event' => 'order.delivered'];

    $response = $this->postJson('/api/webhooks/pathao', $payload);

    $response->assertStatus(202);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://shop-one.test/api/webhooks/pathao'
        && $request->body() === json_encode($payload));

    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->matched_by)->toBe(MatchKey::Invoice->value)
        ->and($delivery->database_name)->toBe('shop_one')
        ->and($delivery->webhook_transfer_id)->toBe($transfer->getKey())
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->delivered_at)->not->toBeNull();
});

it('matches on any of the three tracking columns', function (string $column): void {
    fakeTenant('shop_track', [[$column => 'CB-991']]);

    WebhookTransfer::factory()->courier(Courier::Carrybee)->database('shop_track')->create([
        'target_url' => 'https://shop-track.test',
    ]);

    $this->postJson('/api/webhooks/carrybee', [
        'consignment_id' => 'CB-991',
        'event' => 'order.delivered',
    ])->assertStatus(202);

    expect(WebhookDelivery::query()->sole())
        ->matched_by->toBe(MatchKey::Tracking->value)
        ->status->toBe(DeliveryStatus::Delivered);
})->with(['tracking_id', 'tracking_id2', 'tracking_id3']);

it('matches on the phone number regardless of formatting', function (string $payloadPhone, string $storedPhone): void {
    fakeTenant('shop_phone', [['customer_phone' => $storedPhone]]);

    WebhookTransfer::factory()->courier(Courier::Redx)->database('shop_phone')->create([
        'target_url' => 'https://shop-phone.test',
    ]);

    $this->postJson('/api/webhooks/redx', [
        'customer_phone' => $payloadPhone,
        'status' => 'delivered',
    ])->assertStatus(200);

    expect(WebhookDelivery::query()->sole())
        ->matched_by->toBe(MatchKey::Phone->value)
        ->status->toBe(DeliveryStatus::Delivered);
})->with([
    ['+8801711112222', '01711112222'],
    ['01711112222', '+8801711112222'],
    ['0171-111 2222', '01711112222'],
]);

it('falls back to the customer name only when no other key was sent', function (): void {
    fakeTenant('shop_name', orders: [], customers: [['name' => 'Rahim Uddin']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_name')->create([
        'target_url' => 'https://shop-name.test',
    ]);

    $this->postJson('/api/webhooks/pathao', ['recipient_name' => 'Rahim Uddin'])
        ->assertStatus(202);

    expect(WebhookDelivery::query()->sole())
        ->matched_by->toBe(MatchKey::Name->value)
        ->status->toBe(DeliveryStatus::Delivered);
});

it('does not fall back to the name when a stronger key was sent but did not match', function (): void {
    fakeTenant('shop_strict', orders: [], customers: [['name' => 'Rahim Uddin']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_strict')->create([
        'target_url' => 'https://shop-strict.test',
    ]);

    $this->postJson('/api/webhooks/pathao', [
        'merchant_order_id' => 'INV-NOPE',
        'recipient_name' => 'Rahim Uddin',
    ])->assertStatus(202);

    expect(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Unmatched);
    Http::assertNothingSent();
});

it('fans out to every site mapped to a matching database', function (): void {
    fakeTenant('shop_shared', [['invoice_no' => 'INV-7007']]);

    WebhookTransfer::factory()->courier(Courier::Steadfast)->database('shop_shared')->create([
        'target_url' => 'https://primary.test',
    ]);
    WebhookTransfer::factory()->courier(Courier::Steadfast)->database('shop_shared')->create([
        'target_url' => 'https://mirror.test',
    ]);

    $this->postJson('/api/webhooks/steadfast', ['invoice' => 'INV-7007', 'status' => 'delivered'])
        ->assertStatus(200);

    expect(WebhookDelivery::query()->count())->toBe(2);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://primary.test/api/webhooks/steadfast');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mirror.test/api/webhooks/steadfast');
});

it('fans out across two databases that both recognise the parcel', function (): void {
    fakeTenant('shop_a', [['invoice_no' => 'INV-DUP']]);
    fakeTenant('shop_b', [['invoice_no' => 'INV-DUP']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_a')->create([
        'target_url' => 'https://shop-a.test',
    ]);
    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_b')->create([
        'target_url' => 'https://shop-b.test',
    ]);

    $this->postJson('/api/webhooks/pathao', ['merchant_order_id' => 'INV-DUP'])
        ->assertStatus(202);

    expect(WebhookDelivery::query()->pluck('database_name')->all())
        ->toEqualCanonicalizing(['shop_a', 'shop_b']);
});

it('skips databases that are mapped to a different courier', function (): void {
    fakeTenant('shop_other', [['invoice_no' => 'INV-8008']]);

    WebhookTransfer::factory()->courier(Courier::Redx)->database('shop_other')->create([
        'target_url' => 'https://shop-other.test',
    ]);

    $this->postJson('/api/webhooks/pathao', ['merchant_order_id' => 'INV-8008'])
        ->assertStatus(202);

    expect(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Unmatched);
    Http::assertNothingSent();
});

it('ignores inactive transfers', function (): void {
    fakeTenant('shop_off', [['invoice_no' => 'INV-9009']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_off')->inactive()->create([
        'target_url' => 'https://shop-off.test',
    ]);

    $this->postJson('/api/webhooks/pathao', ['merchant_order_id' => 'INV-9009'])
        ->assertStatus(202);

    expect(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Unmatched);
});

it('records an unmatched delivery when nothing recognises the order', function (): void {
    fakeTenant('shop_empty');

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_empty')->create([
        'target_url' => 'https://shop-empty.test',
    ]);

    $this->postJson('/api/webhooks/pathao', ['merchant_order_id' => 'INV-MISSING'])
        ->assertStatus(202);

    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->status)->toBe(DeliveryStatus::Unmatched)
        ->and($delivery->target_url)->toBeNull()
        ->and($delivery->invoice_no)->toBe('INV-MISSING')
        ->and($delivery->next_attempt_at)->not->toBeNull();

    Http::assertNothingSent();
});

it('preserves the legacy courier paths when forwarding', function (string $path, string $courier, array $payload): void {
    fakeTenant('shop_legacy', [['invoice_no' => 'INV-LEGACY']]);

    WebhookTransfer::factory()->courier(Courier::from($courier))->database('shop_legacy')->create([
        'target_url' => 'https://legacy.test',
    ]);

    $this->postJson($path, $payload)->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://legacy.test'.$path);
})->with([
    ['/api/pathao-webhook', 'pathao', ['merchant_order_id' => 'INV-LEGACY']],
    ['/api/steadfast-webhook', 'steadfast', ['invoice' => 'INV-LEGACY']],
]);

it('passes the courier headers through and lets the client set the transport headers', function (): void {
    fakeTenant('shop_headers', [['invoice_no' => 'INV-HDR']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_headers')->create([
        'target_url' => 'https://headers.test',
    ]);

    $this->postJson(
        '/api/webhooks/pathao',
        ['merchant_order_id' => 'INV-HDR'],
        ['X-PATHAO-Signature' => 'sig-abc'],
    )->assertStatus(202);

    Http::assertSent(function (Request $request): bool {
        $headers = array_change_key_case($request->headers(), CASE_LOWER);

        return ($headers['x-pathao-signature'] ?? []) === ['sig-abc']
            && ($headers['content-type'] ?? []) === ['application/json']
            && ($headers['host'] ?? []) === ['headers.test'];
    });
});

it('returns 404 for an unknown courier', function (): void {
    $this->postJson('/api/webhooks/nonexistent', [])->assertNotFound();
});

it('answers every courier with its own acknowledgement', function (
    string $courier,
    int $status,
    ?string $header,
    ?string $body,
): void {
    $this->seed(CourierSettingSeeder::class);

    $response = $this->postJson("/api/webhooks/{$courier}", ['event' => 'webhook.integration']);

    $response->assertStatus($status);

    if ($header !== null) {
        $secret = CourierSetting::query()->where('courier', $courier)->value('secret');
        expect($response->headers->get($header))->toBe($secret);
    }

    if ($body !== null) {
        expect($response->getContent())->toBe($body);
    }
})->with([
    ['pathao', 202, 'X-Pathao-Merchant-Webhook-Integration-Secret', 'OK'],
    ['carrybee', 202, 'X-CB-Webhook-Integration-Header', '{"message":"Event received"}'],
    ['steadfast', 200, null, '{"status":"success","message":"Webhook received successfully."}'],
    ['paperfly', 200, null, 'OK'],
    ['redx', 200, null, 'OK'],
]);

it('acknowledges with the site secret when the matched site has its own', function (): void {
    fakeTenant('shop_secret', [['invoice_no' => 'INV-SEC']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_secret')->create([
        'target_url' => 'https://secret.test',
        'ack_secret' => 'site-specific-secret',
    ]);

    $this->postJson('/api/webhooks/pathao', ['merchant_order_id' => 'INV-SEC'])
        ->assertStatus(202)
        ->assertHeader('X-Pathao-Merchant-Webhook-Integration-Secret', 'site-specific-secret');
});

it('acknowledges the courier even when the tenant rejects the forward', function (): void {
    forwardResponseStatus(500);

    fakeTenant('shop_down', [['invoice_no' => 'INV-DOWN']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_down')->create([
        'target_url' => 'https://down.test',
    ]);

    $this->postJson('/api/webhooks/pathao', ['merchant_order_id' => 'INV-DOWN'])
        ->assertStatus(202);

    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->response_status)->toBe(500)
        ->and($delivery->last_error)->toBe('HTTP 500')
        ->and($delivery->next_attempt_at)->not->toBeNull();
});

it('keeps only the database with the strongest evidence when several recognise the order', function (): void {
    fakeTenant('shop_owner', [[
        'invoice_no' => 'INV-DUP2',
        'tracking_id' => 'PX-555',
        'customer_phone' => '01711112222',
    ]]);
    fakeTenant('shop_sibling', [['invoice_no' => 'INV-DUP2']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_owner')->create([
        'target_url' => 'https://shop-owner.test',
    ]);
    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_sibling')->create([
        'target_url' => 'https://shop-sibling.test',
    ]);

    $this->postJson('/api/webhooks/pathao', [
        'merchant_order_id' => 'INV-DUP2',
        'consignment_id' => 'PX-555',
    ])->assertStatus(202);

    expect(WebhookDelivery::query()->sole()->database_name)->toBe('shop_owner');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://shop-owner.test/api/webhooks/pathao');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://shop-sibling.test/api/webhooks/pathao');
});

it('drops a database that only shares the customer phone number', function (): void {
    fakeTenant('shop_real', [['invoice_no' => 'INV-6006', 'customer_phone' => '01822223333']]);
    fakeTenant('shop_samephone', [['invoice_no' => 'INV-OTHER', 'customer_phone' => '01822223333']]);

    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_real')->create([
        'target_url' => 'https://shop-real.test',
    ]);
    WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_samephone')->create([
        'target_url' => 'https://shop-samephone.test',
    ]);

    $this->postJson('/api/webhooks/pathao', [
        'merchant_order_id' => 'INV-6006',
        'recipient_phone' => '01822223333',
    ])->assertStatus(202);

    expect(WebhookDelivery::query()->sole()->database_name)->toBe('shop_real');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://shop-samephone.test/api/webhooks/pathao');
});
