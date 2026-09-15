<?php

declare(strict_types=1);

use App\Enums\Courier;
use App\Services\CourierIdentityExtractor;

it('extracts the identity from a pathao payload', function (): void {
    $identity = app(CourierIdentityExtractor::class)->extract(Courier::Pathao, [
        'merchant_order_id' => 'INV-1001',
        'consignment_id' => 'DT250101',
        'recipient_phone' => '01711112222',
        'recipient_name' => 'Rahim Uddin',
        'event' => 'order.delivered',
    ]);

    expect($identity->invoiceNo)->toBe('INV-1001')
        ->and($identity->trackingId)->toBe('DT250101')
        ->and($identity->phone)->toBe('01711112222')
        ->and($identity->name)->toBe('Rahim Uddin');
});

it('extracts the identity from a steadfast payload and falls back to consignment id', function (): void {
    $identity = app(CourierIdentityExtractor::class)->extract(Courier::Steadfast, [
        'invoice' => 'INV-2002',
        'consignment_id' => 'SF-778',
        'status' => 'delivered',
    ]);

    expect($identity->invoiceNo)->toBe('INV-2002')
        ->and($identity->trackingId)->toBe('SF-778');
});

it('extracts the identity from a carrybee payload', function (): void {
    $identity = app(CourierIdentityExtractor::class)->extract(Courier::Carrybee, [
        'merchant_order_id' => 'INV-3003',
        'consignment_id' => 'CB-991',
        'recipient_phone' => '+8801811112222',
    ]);

    expect($identity->invoiceNo)->toBe('INV-3003')
        ->and($identity->trackingId)->toBe('CB-991')
        ->and($identity->phone)->toBe('+8801811112222')
        ->and($identity->name)->toBeNull();
});

it('extracts the identity from a nested paperfly payload', function (): void {
    $identity = app(CourierIdentityExtractor::class)->extract(Courier::Paperfly, [
        'event' => 'parcel.delivered',
        'data' => [
            'merchant_order_reference' => 'INV-4004',
            'order_number' => 'PF-555',
            'recipient_phone' => '01911112222',
            'recipient_name' => 'Karim Mia',
        ],
    ]);

    expect($identity->invoiceNo)->toBe('INV-4004')
        ->and($identity->trackingId)->toBe('PF-555')
        ->and($identity->phone)->toBe('01911112222')
        ->and($identity->name)->toBe('Karim Mia');
});

it('falls back to the paperfly barcode when the order number is absent', function (): void {
    $identity = app(CourierIdentityExtractor::class)->extract(Courier::Paperfly, [
        'data' => ['barcode' => 'PF-BARCODE'],
    ]);

    expect($identity->trackingId)->toBe('PF-BARCODE')
        ->and($identity->invoiceNo)->toBeNull();
});

it('extracts the identity from a redx payload', function (): void {
    $identity = app(CourierIdentityExtractor::class)->extract(Courier::Redx, [
        'invoice_number' => 'INV-5005',
        'tracking_number' => 'RX-123',
        'customer_phone' => '01611112222',
        'customer_name' => 'Jamil',
    ]);

    expect($identity->invoiceNo)->toBe('INV-5005')
        ->and($identity->trackingId)->toBe('RX-123')
        ->and($identity->phone)->toBe('01611112222')
        ->and($identity->name)->toBe('Jamil');
});

it('never throws on a payload that is missing every mapped key', function (): void {
    $identity = app(CourierIdentityExtractor::class)->extract(Courier::Pathao, [
        'event' => 'webhook.integration',
    ]);

    expect($identity->invoiceNo)->toBeNull()
        ->and($identity->trackingId)->toBeNull()
        ->and($identity->phone)->toBeNull()
        ->and($identity->name)->toBeNull()
        ->and($identity->isEmpty())->toBeTrue();
});

it('ignores array values and blank strings', function (): void {
    $identity = app(CourierIdentityExtractor::class)->extract(Courier::Pathao, [
        'merchant_order_id' => '   ',
        'consignment_id' => ['nested'],
    ]);

    expect($identity->invoiceNo)->toBeNull()
        ->and($identity->trackingId)->toBeNull();
});
