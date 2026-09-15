<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use App\Models\WebhookTransfer;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class RelayStatsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $since = CarbonImmutable::now()->subDay();

        $received = WebhookDelivery::query()->where('created_at', '>=', $since)->count();
        $delivered = WebhookDelivery::query()
            ->where('created_at', '>=', $since)
            ->where('status', DeliveryStatus::Delivered->value)
            ->count();
        $failed = WebhookDelivery::query()->where('status', DeliveryStatus::Failed->value)->count();
        $unmatched = WebhookDelivery::query()->where('status', DeliveryStatus::Unmatched->value)->count();

        $rate = $received > 0 ? round($delivered / $received * 100) : 100;

        return [
            Stat::make('Webhooks today', (string) $received)
                ->description('Received in the last 24 hours')
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->chart($this->hourlyCounts())
                ->color('primary'),

            Stat::make('Delivered', $delivered.' of '.$received)
                ->description($rate.'% forwarded successfully')
                ->descriptionIcon($rate >= 95 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($rate >= 95 ? 'success' : ($rate >= 80 ? 'warning' : 'danger')),

            Stat::make('Awaiting retry', (string) $failed)
                ->description($failed === 0 ? 'Nothing stuck' : 'Failed forwards in the retry queue')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color($failed === 0 ? 'success' : 'danger'),

            Stat::make('Unmatched', (string) $unmatched)
                ->description('No mapped site recognised the order')
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color($unmatched === 0 ? 'success' : 'warning'),

            Stat::make('Active routes', (string) WebhookTransfer::query()->where('is_active', true)->count())
                ->description(WebhookTransfer::query()->where('is_active', true)->distinct()->count('database_name').' tenant databases')
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->color('primary'),

            Stat::make('Sites', (string) WebhookTransfer::query()->distinct()->count('target_url'))
                ->description('Distinct ecommerce targets')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('primary'),

            Stat::make('Couriers wired', (string) WebhookTransfer::query()->where('is_active', true)->distinct()->count('courier'))
                ->description('Couriers with at least one active route')
                ->descriptionIcon('heroicon-m-truck')
                ->color('primary'),

            Stat::make('Median attempts', (string) round((float) WebhookDelivery::query()
                ->where('created_at', '>=', $since)
                ->avg('attempts'), 2))
                ->description('Average forwards per webhook')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('primary'),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function hourlyCounts(): array
    {
        $counts = [];

        for ($hour = 23; $hour >= 0; $hour--) {
            $start = CarbonImmutable::now()->subHours($hour)->startOfHour();

            $counts[] = WebhookDelivery::query()
                ->whereBetween('created_at', [$start, $start->addHour()])
                ->count();
        }

        return $counts;
    }
}
