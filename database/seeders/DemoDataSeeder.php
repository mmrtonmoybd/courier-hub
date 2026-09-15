<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use App\Enums\MatchKey;
use App\Models\WebhookDelivery;
use App\Models\WebhookTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

/**
 * Fills the delivery log with two weeks of realistic traffic across every
 * mapped site and courier, in every status, so the dashboard, filters and retry
 * flows can be exercised without waiting for real webhooks.
 *
 * Demo data only. Never run this against a live relay.
 */
final class DemoDataSeeder extends Seeder
{
    private const DAYS = 14;

    private const WEBHOOKS_PER_SITE_PER_DAY = 3;

    public function run(): void
    {
        $this->call([
            CourierSettingSeeder::class,
            WebhookTransferSeeder::class,
        ]);

        $transfers = WebhookTransfer::query()->where('is_active', true)->get();

        if ($transfers->isEmpty()) {
            $this->command?->warn('No webhook transfers to build demo traffic from.');

            return;
        }

        $rows = [];

        foreach (range(self::DAYS - 1, 0) as $daysAgo) {
            foreach ($transfers as $transfer) {
                foreach (range(1, self::WEBHOOKS_PER_SITE_PER_DAY) as $sequence) {
                    $rows[] = $this->deliveryRow($transfer, $daysAgo, $sequence);
                }
            }
        }

        // A handful of webhooks nobody claimed, as they arrive in real life.
        foreach (range(1, 6) as $index) {
            $rows[] = $this->unmatchedRow($index);
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            WebhookDelivery::query()->insert($chunk);
        }

        $this->command?->info(sprintf(
            'Seeded %d demo deliveries across %d routes.',
            count($rows),
            $transfers->count(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryRow(WebhookTransfer $transfer, int $daysAgo, int $sequence): array
    {
        $courier = $transfer->courier;
        $receivedAt = CarbonImmutable::now()
            ->subDays($daysAgo)
            ->setTime(random_int(8, 21), random_int(0, 59));

        $invoice = sprintf('INV-%d%02d%d', 1000 + $transfer->getKey(), $daysAgo, $sequence);
        $tracking = mb_strtoupper(mb_substr($courier->value, 0, 2)).'-'.random_int(100000, 999999);
        $phone = '01'.random_int(3, 9).random_int(10000000, 99999999);
        $matchedBy = Arr::random([MatchKey::Invoice, MatchKey::Invoice, MatchKey::Tracking, MatchKey::Phone]);
        $status = $this->weightedStatus();

        $payload = $this->payload($courier, $invoice, $tracking, $phone);
        $path = '/api/webhooks/'.$courier->value;

        $attempts = match ($status) {
            DeliveryStatus::Delivered => 1,
            DeliveryStatus::Failed => random_int(1, 4),
            DeliveryStatus::Unmatched => 1,
        };

        return [
            'courier' => $courier->value,
            'incoming_path' => $path,
            'forward_path' => $path,
            'headers' => json_encode([
                'content-type' => ['application/json'],
                'user-agent' => [$courier->label().'-Webhook/1.0'],
            ]),
            'raw_body' => json_encode($payload),
            'payload' => json_encode($payload),
            'invoice_no' => $invoice,
            'tracking_id' => $tracking,
            'phone' => $phone,
            'name' => null,
            'matched_by' => $matchedBy->value,
            'database_name' => $transfer->database_name,
            'webhook_transfer_id' => $transfer->getKey(),
            'target_url' => $transfer->forwardUrl($path),
            'status' => $status->value,
            'attempts' => $attempts,
            'next_attempt_at' => $status === DeliveryStatus::Failed
                ? CarbonImmutable::now()->addMinutes(random_int(1, 30))
                : null,
            'response_status' => match ($status) {
                DeliveryStatus::Delivered => 200,
                DeliveryStatus::Failed => Arr::random([500, 502, 504]),
                DeliveryStatus::Unmatched => null,
            },
            'response_body' => $status === DeliveryStatus::Delivered ? 'OK' : null,
            'last_error' => $status === DeliveryStatus::Failed ? 'HTTP 500' : null,
            'delivered_at' => $status === DeliveryStatus::Delivered ? $receivedAt->addSeconds(random_int(1, 4)) : null,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unmatchedRow(int $index): array
    {
        $courier = Arr::random(Courier::cases());
        $receivedAt = CarbonImmutable::now()->subMinutes($index * 7);
        $invoice = 'INV-ORPHAN-'.$index;
        $payload = $this->payload($courier, $invoice, 'UNKNOWN-'.$index, '01700000000');
        $path = '/api/webhooks/'.$courier->value;

        return [
            'courier' => $courier->value,
            'incoming_path' => $path,
            'forward_path' => $path,
            'headers' => json_encode(['content-type' => ['application/json']]),
            'raw_body' => json_encode($payload),
            'payload' => json_encode($payload),
            'invoice_no' => $invoice,
            'tracking_id' => 'UNKNOWN-'.$index,
            'phone' => '01700000000',
            'name' => null,
            'matched_by' => null,
            'database_name' => null,
            'webhook_transfer_id' => null,
            'target_url' => null,
            'status' => DeliveryStatus::Unmatched->value,
            'attempts' => random_int(1, 4),
            'next_attempt_at' => CarbonImmutable::now()->addMinute(),
            'response_status' => null,
            'response_body' => null,
            'last_error' => 'No mapped tenant database recognised this order.',
            'delivered_at' => null,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ];
    }

    /**
     * Builds a payload shaped exactly like the courier's own, straight from the
     * field map, so the demo rows survive a change to `config/couriers.php`.
     *
     * @return array<string, mixed>
     */
    private function payload(Courier $courier, string $invoice, string $tracking, string $phone): array
    {
        /** @var array<string, array<int, string>> $fields */
        $fields = (array) config("couriers.{$courier->value}.fields", []);
        $payload = [];

        foreach (['invoice' => $invoice, 'tracking' => $tracking, 'phone' => $phone] as $key => $value) {
            $path = $fields[$key][0] ?? null;

            if (is_string($path)) {
                Arr::set($payload, $path, $value);
            }
        }

        Arr::set($payload, 'event', 'order.delivered');

        return $payload;
    }

    private function weightedStatus(): DeliveryStatus
    {
        $roll = random_int(1, 100);

        return match (true) {
            $roll <= 88 => DeliveryStatus::Delivered,
            $roll <= 96 => DeliveryStatus::Failed,
            default => DeliveryStatus::Unmatched,
        };
    }
}
