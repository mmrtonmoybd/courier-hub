<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Courier;
use App\Models\WebhookTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookTransfer>
 */
final class WebhookTransferFactory extends Factory
{
    protected $model = WebhookTransfer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'target_url' => 'https://'.fake()->unique()->domainName(),
            'courier' => fake()->randomElement(Courier::cases())->value,
            'database_name' => 'tenant_'.fake()->unique()->userName(),
            'ack_secret' => null,
            'inbound_token' => (string) Str::uuid(),
            'priority' => 0,
            'is_active' => true,
        ];
    }

    public function courier(Courier $courier): self
    {
        return $this->state(fn (): array => ['courier' => $courier->value]);
    }

    public function database(string $database): self
    {
        return $this->state(fn (): array => ['database_name' => $database]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
