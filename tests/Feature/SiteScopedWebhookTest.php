<?php

declare(strict_types=1);

use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use App\Enums\MatchKey;
use App\Models\WebhookDelivery;
use App\Models\WebhookTransfer;
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

it('gives every transfer its own inbound token and url', function (): void {
    $transfer = WebhookTransfer::factory()->courier(Courier::Pathao)->create([
        'target_url' => 'https://site.test',
        'inbound_token' => null,
    ]);

    expect($transfer->inbound_token)->not->toBeEmpty()
        ->and($transfer->inboundUrl())->toBe(url('/api/w/'.$transfer->inbound_token.'/webhooks/pathao'));
});

it('forwards a site-scoped webhook without needing a database lookup', function (): void {
    $transfer = WebhookTransfer::factory()->courier(Courier::Pathao)->database('shop_token')->create([
        'target_url' => 'https://token.test',
        'ack_secret' => 'per-site-secret',
    ]);

    $payload = ['event' => 'webhook.integration'];

    $this->postJson("/api/w/{$transfer->inbound_token}/webhooks/pathao", $payload)
        ->assertStatus(202)
        ->assertHeader('X-Pathao-Merchant-Webhook-Integration-Secret', 'per-site-secret');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://token.test/api/webhooks/pathao'
        && $request->body() === json_encode($payload));

    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->matched_by)->toBe(MatchKey::Token->value)
        ->and($delivery->database_name)->toBe('shop_token')
        ->and($delivery->incoming_path)->toBe("/api/w/{$transfer->inbound_token}/webhooks/pathao")
        ->and($delivery->forward_path)->toBe('/api/webhooks/pathao');
});

it('rejects a site-scoped webhook whose token does not match the courier', function (): void {
    $transfer = WebhookTransfer::factory()->courier(Courier::Pathao)->create();

    $this->postJson("/api/w/{$transfer->inbound_token}/webhooks/redx", [])->assertNotFound();
    Http::assertNothingSent();
});

it('rejects a site-scoped webhook for an inactive transfer', function (): void {
    $transfer = WebhookTransfer::factory()->courier(Courier::Pathao)->inactive()->create();

    $this->postJson("/api/w/{$transfer->inbound_token}/webhooks/pathao", [])->assertNotFound();
});

it('rejects an unknown site token', function (): void {
    $this->postJson('/api/w/not-a-real-token/webhooks/pathao', [])->assertNotFound();
});
