<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourierSettings;

use App\Filament\Resources\CourierSettings\Pages\EditCourierSetting;
use App\Filament\Resources\CourierSettings\Pages\ListCourierSettings;
use App\Filament\Resources\CourierSettings\Schemas\CourierSettingForm;
use App\Filament\Resources\CourierSettings\Tables\CourierSettingsTable;
use App\Models\CourierSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CourierSettingResource extends Resource
{
    protected static ?string $model = CourierSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Setting & Configuration';

    protected static ?string $navigationLabel = 'Courier Secrets';

    protected static ?string $modelLabel = 'courier secret';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return CourierSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CourierSettingsTable::configure($table);
    }

    /**
     * The five supported couriers are seeded; there is nothing to add.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourierSettings::route('/'),
            'edit' => EditCourierSetting::route('/{record}/edit'),
        ];
    }
}
