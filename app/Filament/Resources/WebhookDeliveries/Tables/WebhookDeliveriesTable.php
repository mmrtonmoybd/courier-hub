<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookDeliveries\Tables;

use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use App\Filament\Resources\WebhookTransfers\Schemas\WebhookTransferForm;
use App\Models\WebhookDelivery;
use App\Services\WebhookForwarder;
use App\Services\WebhookRelayService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class WebhookDeliveriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('courier')
                    ->badge()
                    ->formatStateUsing(fn (Courier $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (DeliveryStatus $state): string => match ($state) {
                        DeliveryStatus::Delivered => 'success',
                        DeliveryStatus::Failed => 'danger',
                        DeliveryStatus::Unmatched => 'warning',
                    })
                    ->formatStateUsing(fn (DeliveryStatus $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('invoice_no')
                    ->label('Invoice')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('tracking_id')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                TextColumn::make('phone')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                TextColumn::make('matched_by')
                    ->label('Matched by')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('database_name')
                    ->label('Tenant database')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('webhookTransfer.name')
                    ->label('Site')
                    ->placeholder('—'),
                TextColumn::make('attempts')
                    ->sortable(),
                TextColumn::make('response_status')
                    ->label('HTTP')
                    ->placeholder('—'),
                TextColumn::make('next_attempt_at')
                    ->label('Next try')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('courier')
                    ->options(Courier::options()),
                SelectFilter::make('status')
                    ->options(DeliveryStatus::options()),
                SelectFilter::make('database_name')
                    ->label('Tenant database')
                    ->options(fn (): array => WebhookTransferForm::databaseOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('retry')
                    ->label('Retry now')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (WebhookDelivery $record): void {
                        $delivered = self::retry($record);

                        Notification::make()
                            ->title($delivered ? 'Delivered.' : 'Still not delivered.')
                            ->{$delivered ? 'success' : 'warning'}()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('retrySelected')
                        ->label('Retry selected')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $delivered = $records->filter(
                                fn (WebhookDelivery $record): bool => self::retry($record),
                            )->count();

                            Notification::make()
                                ->title("{$delivered} of {$records->count()} delivered.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * An unmatched row is re-resolved first: its owner may exist by now.
     */
    private static function retry(WebhookDelivery $record): bool
    {
        if ($record->status === DeliveryStatus::Unmatched) {
            return app(WebhookRelayService::class)->retryUnmatched($record);
        }

        if (blank($record->target_url)) {
            return false;
        }

        return app(WebhookForwarder::class)->forward($record);
    }
}
