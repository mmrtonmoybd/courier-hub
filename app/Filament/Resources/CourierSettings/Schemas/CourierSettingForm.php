<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourierSettings\Schemas;

use App\Enums\Courier;
use App\Models\CourierSetting;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CourierSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Courier-wide secret')
                    ->description('Used in the acknowledgement returned to this courier when the site is not known, or when the site has no secret of its own.')
                    ->schema([
                        Select::make('courier')
                            ->options(Courier::options())
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('secret')
                            ->label('Secret')
                            ->helperText(fn (?CourierSetting $record): string => self::headerHelper($record?->courier))
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->inline(false),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
            ]);
    }

    private static function headerHelper(?Courier $courier): string
    {
        if (! $courier instanceof Courier) {
            return 'Returned to the courier when it expects a secret header.';
        }

        $header = config("couriers.{$courier->value}.ack.header");

        return is_string($header)
            ? "Returned in the {$header} header."
            : 'This courier does not expect a secret header; stored for reference only.';
    }
}
