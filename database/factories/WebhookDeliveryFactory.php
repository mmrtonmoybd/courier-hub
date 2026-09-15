<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
final class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $invoice = (string) fake()->unique()->numberBetween(10000, 99999);

        return [
            'courier' => Courier::Pathao->value,
            'incoming_path' => '/api/webhooks/pathao',
            'forward_path' => '/api/webhooks/pathao',
            'headers' => ['content-type' => ['application/json']],
            'raw_body' => json_encode(['merchant_order_id' => $invoice]),
            'payload' => ['merchant_order_id' => $invoice],
            'invoice_no' => $invoice,
            'tracking_id' => null,
            'phone' => null,
            'name' => null,
            'status' => DeliveryStatus::Delivered->value,
            'attempts' => 1,
        ];
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Failed->value,
            'target_url' => 'https://tenant.test/api/webhooks/pathao',
            'next_attempt_at' => now()->subMinute(),
            'last_error' => 'HTTP 500',
        ]);
    }

    public function unmatched(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Unmatched->value,
            'database_name' => null,
            'target_url' => null,
            'next_attempt_at' => now()->subMinute(),
        ]);
    }
}
