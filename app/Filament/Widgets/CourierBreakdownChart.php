<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\Courier;
use App\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

final class CourierBreakdownChart extends ChartWidget
{
    protected ?string $heading = 'Webhooks by courier';

    protected ?string $description = 'Last 7 days.';

    protected ?string $pollingInterval = '120s';

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $counts = WebhookDelivery::query()
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(7))
            ->selectRaw('courier, count(*) as aggregate')
            ->groupBy('courier')
            ->pluck('aggregate', 'courier');

        $labels = [];
        $data = [];

        foreach (Courier::cases() as $courier) {
            $labels[] = $courier->label();
            $data[] = (int) ($counts[$courier->value] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Webhooks',
                    'data' => $data,
                    'backgroundColor' => ['#f97316', '#0ea5e9', '#16a34a', '#a855f7', '#ef4444'],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
