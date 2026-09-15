<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourierSettings\Tables;

use App\Enums\Courier;
use App\Models\CourierSetting;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CourierSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('courier')
                    ->badge()
                    ->formatStateUsing(fn (Courier $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('secret')
                    ->label('Secret')
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('ack_header')
                    ->label('Ack header')
                    ->state(fn (CourierSetting $record): string => (string) (config("couriers.{$record->courier->value}.ack.header") ?? '—')),
                TextColumn::make('inbound_url')
                    ->label('Shared inbound URL')
                    ->state(fn (CourierSetting $record): string => url('/api/webhooks/'.$record->courier->value))
                    ->copyable(),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->defaultSort('courier')
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
