<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Keeps the delivery log bounded. With 10-30 sites and several events per
 * parcel the table grows quickly, so delivered rows are dropped sooner than
 * rows that still need a human look.
 */
final class WebhooksPrune extends Command
{
    protected $signature = 'webhooks:prune';

    protected $description = 'Prune old webhook delivery log rows';

    public function handle(): int
    {
        $deliveredDays = (int) config('couriers.retention.delivered_days', 30);
        $otherDays = (int) config('couriers.retention.other_days', 90);

        $delivered = WebhookDelivery::query()
            ->where('status', DeliveryStatus::Delivered->value)
            ->where('created_at', '<', CarbonImmutable::now()->subDays($deliveredDays))
            ->delete();

        $others = WebhookDelivery::query()
            ->whereIn('status', [DeliveryStatus::Failed->value, DeliveryStatus::Unmatched->value])
            ->where('created_at', '<', CarbonImmutable::now()->subDays($otherDays))
            ->delete();

        $this->components->info("Pruned {$delivered} delivered and {$others} unresolved delivery rows.");

        return self::SUCCESS;
    }
}
