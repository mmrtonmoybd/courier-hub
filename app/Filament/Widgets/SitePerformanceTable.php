<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use App\Models\WebhookTransfer;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * How each routing row has been performing over the last week. With 10-30 sites
 * this is the quickest way to spot the one that stopped accepting forwards.
 */
final class SitePerformanceTable extends TableWidget
{
    protected static ?string $heading = 'Site performance (7 days)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $since = CarbonImmutable::now()->subDays(7);

        return $table
            ->query(
                WebhookTransfer::query()
                    ->where('is_active', true)
                    ->withCount([
                        'deliveries as delivered_count' => fn (Builder $query) => $query
                            ->where('status', DeliveryStatus::Delivered->value)
                            ->where('created_at', '>=', $since),
                        'deliveries as failed_count' => fn (Builder $query) => $query
                            ->where('status', DeliveryStatus::Failed->value)
                            ->where('created_at', '>=', $since),
                    ])
            )
            ->defaultSort('delivered_count', 'desc')
            ->paginated([5, 10, 25])
            ->columns([
                TextColumn::make('name')
                    ->label('Site')
                    ->searchable(),
                TextColumn::make('courier')
                    ->badge()
                    ->formatStateUsing(fn (Courier $state): string => $state->label()),
                TextColumn::make('database_name')
                    ->label('Tenant database'),
                TextColumn::make('delivered_count')
                    ->label('Delivered')
                    ->badge()
                    ->color('success')
                    ->sortable(),
                TextColumn::make('failed_count')
                    ->label('Failed')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('last_seen')
                    ->label('Last webhook')
                    ->state(fn (WebhookTransfer $record): ?string => WebhookDelivery::query()
                        ->where('webhook_transfer_id', $record->getKey())
                        ->max('created_at'))
                    ->since()
                    ->placeholder('never'),
            ]);
    }
}
