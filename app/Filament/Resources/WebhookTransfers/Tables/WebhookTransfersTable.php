<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookTransfers\Tables;

use App\Enums\Courier;
use App\Filament\Resources\WebhookTransfers\Schemas\WebhookTransferForm;
use App\Models\WebhookTransfer;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class WebhookTransfersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Site')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('courier')
                    ->badge()
                    ->formatStateUsing(fn (Courier $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('database_name')
                    ->label('Tenant database')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('target_url')
                    ->label('Site address')
                    ->searchable()
                    ->copyable(),
                IconColumn::make('ack_secret')
                    ->label('Own secret')
                    ->boolean()
                    ->state(fn (WebhookTransfer $record): bool => filled($record->ack_secret)),
                TextColumn::make('inbound_url')
                    ->label('Site inbound URL')
                    ->state(fn (WebhookTransfer $record): string => $record->inboundUrl())
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deliveries_count')
                    ->label('Deliveries')
                    ->counts('deliveries')
                    ->sortable(),
                TextColumn::make('priority')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('courier')
                    ->options(Courier::options()),
                SelectFilter::make('database_name')
                    ->label('Tenant database')
                    ->options(fn (): array => WebhookTransferForm::databaseOptions()),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->icon('heroicon-o-check-circle')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->icon('heroicon-o-pause-circle')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
