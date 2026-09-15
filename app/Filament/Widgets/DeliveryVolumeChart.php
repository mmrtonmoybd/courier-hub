<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

final class DeliveryVolumeChart extends ChartWidget
{
    protected ?string $heading = 'Forwarding volume';

    protected ?string $description = 'Webhooks received per day, by outcome.';

    protected ?string $pollingInterval = '120s';

    protected int|string|array $columnSpan = 2;

    public ?string $filter = '14';

    /**
     * @return array<string, string>
     */
    protected function getFilters(): array
    {
        return [
            '7' => 'Last 7 days',
            '14' => 'Last 14 days',
            '30' => 'Last 30 days',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 14);
        $labels = [];
        $delivered = [];
        $failed = [];
        $unmatched = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = CarbonImmutable::now()->subDays($offset)->startOfDay();
            $labels[] = $day->format('d M');

            $counts = WebhookDelivery::query()
                ->whereBetween('created_at', [$day, $day->endOfDay()])
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $delivered[] = (int) ($counts[DeliveryStatus::Delivered->value] ?? 0);
            $failed[] = (int) ($counts[DeliveryStatus::Failed->value] ?? 0);
            $unmatched[] = (int) ($counts[DeliveryStatus::Unmatched->value] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Delivered',
                    'data' => $delivered,
                    'borderColor' => '#16a34a',
                    'backgroundColor' => 'rgba(22, 163, 74, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Failed',
                    'data' => $failed,
                    'borderColor' => '#dc2626',
                    'backgroundColor' => 'rgba(220, 38, 38, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Unmatched',
                    'data' => $unmatched,
                    'borderColor' => '#f97316',
                    'backgroundColor' => 'rgba(249, 115, 22, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
