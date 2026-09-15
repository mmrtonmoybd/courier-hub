<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookTransfers\Schemas;

use App\Enums\Courier;
use App\Models\WebhookTransfer;
use App\Rules\HostOnlyUrl;
use App\Services\TenantDatabaseCatalog;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class WebhookTransferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transfer')
                    ->description('Where a courier webhook is forwarded, and which tenant database proves the order belongs there.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Site name')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(1),
                            TextInput::make('target_url')
                                ->label('Site address')
                                ->placeholder('https://shop.example.com')
                                ->helperText('Scheme and host only. The courier path is appended automatically.')
                                ->required()
                                ->rules([new HostOnlyUrl])
                                ->maxLength(255)
                                ->columnSpan(1),
                            Select::make('courier')
                                ->options(Courier::options())
                                ->required()
                                ->native(false)
                                ->columnSpan(1),
                            Select::make('database_name')
                                ->label('Tenant database')
                                ->options(fn (): array => self::databaseOptions())
                                ->searchable()
                                ->required()
                                ->helperText('Databases on the shared credentials that contain an orders table.')
                                ->columnSpan(1),
                        ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Courier credentials')
                    ->description('Each site can hold its own secret per courier. Leave empty to fall back to the courier-wide secret.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('ack_secret')
                                ->label('Site webhook secret')
                                ->helperText(fn (Get $get): string => self::secretHelper($get('courier')))
                                ->maxLength(255)
                                ->columnSpan(1),
                            TextInput::make('inbound_token')
                                ->label('Inbound token')
                                ->disabled()
                                ->dehydrated(false)
                                ->helperText('Generated automatically. Used by this site\'s own inbound URL.')
                                ->columnSpan(1),
                        ]),
                        TextInput::make('inbound_url')
                            ->label('Site inbound URL (give this to the courier)')
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (TextInput $component, ?WebhookTransfer $record): void {
                                $component->state($record?->exists ? $record->inboundUrl() : 'Available after saving.');
                            })
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Routing')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('priority')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->helperText('Lower numbers are looked up first.')
                                ->columnSpan(1),
                            Toggle::make('is_active')
                                ->default(true)
                                ->inline(false)
                                ->columnSpan(1),
                        ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function databaseOptions(): array
    {
        return app(TenantDatabaseCatalog::class)->options();
    }

    private static function secretHelper(mixed $courier): string
    {
        $case = is_string($courier) ? Courier::tryFrom($courier) : null;

        if (! $case instanceof Courier) {
            return 'Sent back to the courier in its acknowledgement header, when that courier uses one.';
        }

        $header = config("couriers.{$case->value}.ack.header");

        return is_string($header)
            ? "Returned to the courier in the {$header} header."
            : 'This courier does not expect a secret header; the value is stored for reference only.';
    }
}
