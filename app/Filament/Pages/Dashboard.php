<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\AttentionNeededTable;
use App\Filament\Widgets\CourierBreakdownChart;
use App\Filament\Widgets\DeliveryVolumeChart;
use App\Filament\Widgets\RelayStatsOverview;
use App\Filament\Widgets\SitePerformanceTable;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

final class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Relay overview';

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'md' => 2,
            'xl' => 3,
        ];
    }

    /**
     * @return array<int, class-string>
     */
    public function getWidgets(): array
    {
        return [
            RelayStatsOverview::class,
            DeliveryVolumeChart::class,
            CourierBreakdownChart::class,
            SitePerformanceTable::class,
            AttentionNeededTable::class,
        ];
    }
}
