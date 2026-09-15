<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use App\Filament\Resources\WebhookDeliveries\WebhookDeliveryResource;
use App\Models\WebhookDelivery;
use App\Services\WebhookForwarder;
use App\Services\WebhookRelayService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Everything the relay could not hand over yet, newest first.
 */
final class AttentionNeededTable extends TableWidget
{
    protected static ?string $heading = 'Needs attention';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                WebhookDelivery::query()->whereIn('status', [
                    DeliveryStatus::Failed->value,
                    DeliveryStatus::Unmatched->value,
                ])
            )
            ->defaultSort('created_at', 'desc')
            ->paginated([5, 10, 25])
            ->emptyStateHeading('Nothing pending')
            ->emptyStateDescription('Every webhook received so far reached its site.')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Received')
                    ->since()
                    ->sortable(),
                TextColumn::make('courier')
                    ->badge()
                    ->formatStateUsing(fn (Courier $state): string => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (DeliveryStatus $state): string => $state === DeliveryStatus::Failed ? 'danger' : 'warning')
                    ->formatStateUsing(fn (DeliveryStatus $state): string => $state->label()),
                TextColumn::make('invoice_no')
                    ->label('Invoice')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('database_name')
                    ->label('Tenant')
                    ->placeholder('—'),
                TextColumn::make('attempts'),
                TextColumn::make('next_attempt_at')
                    ->label('Next try')
                    ->since()
                    ->placeholder('—'),
                TextColumn::make('last_error')
                    ->label('Error')
                    ->limit(40)
                    ->tooltip(fn (WebhookDelivery $record): ?string => $record->last_error)
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (WebhookDelivery $record): void {
                        $delivered = $record->status === DeliveryStatus::Unmatched
                            ? app(WebhookRelayService::class)->retryUnmatched($record)
                            : (filled($record->target_url) && app(WebhookForwarder::class)->forward($record));

                        Notification::make()
                            ->title($delivered ? 'Delivered.' : 'Still not delivered.')
                            ->{$delivered ? 'success' : 'warning'}()
                            ->send();
                    }),
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (WebhookDelivery $record): string => WebhookDeliveryResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
