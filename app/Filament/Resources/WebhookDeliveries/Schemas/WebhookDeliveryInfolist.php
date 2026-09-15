<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookDeliveries\Schemas;

use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WebhookDeliveryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Routing')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('courier')
                                ->badge()
                                ->formatStateUsing(fn (Courier $state): string => $state->label()),
                            TextEntry::make('status')
                                ->badge()
                                ->color(fn (DeliveryStatus $state): string => match ($state) {
                                    DeliveryStatus::Delivered => 'success',
                                    DeliveryStatus::Failed => 'danger',
                                    DeliveryStatus::Unmatched => 'warning',
                                })
                                ->formatStateUsing(fn (DeliveryStatus $state): string => $state->label()),
                            TextEntry::make('matched_by')
                                ->label('Matched by')
                                ->placeholder('—'),
                            TextEntry::make('database_name')
                                ->label('Tenant database')
                                ->placeholder('—'),
                            TextEntry::make('webhookTransfer.name')
                                ->label('Site')
                                ->placeholder('—'),
                            TextEntry::make('target_url')
                                ->label('Forwarded to')
                                ->copyable()
                                ->placeholder('—'),
                        ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Identity')
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('invoice_no')->placeholder('—'),
                            TextEntry::make('tracking_id')->placeholder('—'),
                            TextEntry::make('phone')->placeholder('—'),
                            TextEntry::make('name')->label('Customer name')->placeholder('—'),
                        ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Attempts')
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('attempts'),
                            TextEntry::make('response_status')->label('Response status')->placeholder('—'),
                            TextEntry::make('next_attempt_at')->dateTime()->placeholder('—'),
                            TextEntry::make('delivered_at')->dateTime()->placeholder('—'),
                        ]),
                        TextEntry::make('last_error')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('response_body')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Request')
                    ->collapsed()
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('incoming_path'),
                            TextEntry::make('forward_path'),
                        ]),
                        TextEntry::make('headers')
                            ->state(fn (WebhookDelivery $record): string => self::pretty($record->headers))
                            ->columnSpanFull(),
                        TextEntry::make('payload')
                            ->label('Payload')
                            ->state(fn (WebhookDelivery $record): string => self::pretty($record->payload))
                            ->columnSpanFull(),
                        TextEntry::make('raw_body')
                            ->label('Raw body (forwarded verbatim)')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function pretty(mixed $value): string
    {
        if (! is_array($value)) {
            return '—';
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
