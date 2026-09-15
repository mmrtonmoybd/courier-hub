<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookDeliveries;

use App\Filament\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use App\Filament\Resources\WebhookDeliveries\Pages\ViewWebhookDelivery;
use App\Filament\Resources\WebhookDeliveries\Schemas\WebhookDeliveryInfolist;
use App\Filament\Resources\WebhookDeliveries\Tables\WebhookDeliveriesTable;
use App\Models\WebhookDelivery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WebhookDeliveryResource extends Resource
{
    protected static ?string $model = WebhookDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|\UnitEnum|null $navigationGroup = 'Relay';

    protected static ?string $navigationLabel = 'Delivery Log';

    protected static ?string $modelLabel = 'delivery';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return WebhookDeliveryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WebhookDeliveriesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookDeliveries::route('/'),
            'view' => ViewWebhookDelivery::route('/{record}'),
        ];
    }
}
