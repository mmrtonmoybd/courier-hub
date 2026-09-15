<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use App\Services\WebhookForwarder;
use App\Services\WebhookRelayService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Cron-driven sweep that replaces a queue worker: retries forwards that failed
 * and re-resolves webhooks that arrived before their order existed.
 */
final class WebhooksRetry extends Command
{
    protected $signature = 'webhooks:retry {--limit=500 : Maximum deliveries to process in one sweep}';

    protected $description = 'Retry failed webhook forwards and re-resolve unmatched webhooks';

    public function handle(WebhookForwarder $forwarder, WebhookRelayService $relay): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $retriedFailed = 0;
        $promoted = 0;
        $stillUnmatched = 0;

        $this->dueFailed($limit)->each(function (WebhookDelivery $delivery) use ($forwarder, &$retriedFailed): void {
            $forwarder->forward($delivery);
            $retriedFailed++;
        });

        $this->dueUnmatched($limit)->each(function (WebhookDelivery $delivery) use ($relay, &$promoted, &$stillUnmatched): void {
            if ($relay->retryUnmatched($delivery)) {
                $promoted++;

                return;
            }

            $stillUnmatched++;
        });

        $this->components->info(sprintf(
            'Retried %d failed, promoted %d unmatched, %d still unmatched.',
            $retriedFailed,
            $promoted,
            $stillUnmatched,
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, WebhookDelivery>
     */
    private function dueFailed(int $limit): Collection
    {
        return WebhookDelivery::query()
            ->where('status', DeliveryStatus::Failed->value)
            ->whereNotNull('target_url')
            ->where('attempts', '<', (int) config('couriers.retry.max_failed_attempts', 20))
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', CarbonImmutable::now())
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Unmatched webhooks are only re-resolved for a short window: after that the
     * order is simply not ours.
     *
     * @return Collection<int, WebhookDelivery>
     */
    private function dueUnmatched(int $limit): Collection
    {
        $window = (int) config('couriers.retry.unmatched.window_minutes', 30);

        return WebhookDelivery::query()
            ->where('status', DeliveryStatus::Unmatched->value)
            ->where('attempts', '<', (int) config('couriers.retry.unmatched.max_attempts', 10))
            ->where('created_at', '>=', CarbonImmutable::now()->subMinutes($window))
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', CarbonImmutable::now())
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get();
    }
}
