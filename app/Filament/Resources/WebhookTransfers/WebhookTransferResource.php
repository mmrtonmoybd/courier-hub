<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookTransfers;

use App\Filament\Resources\WebhookTransfers\Pages\CreateWebhookTransfer;
use App\Filament\Resources\WebhookTransfers\Pages\EditWebhookTransfer;
use App\Filament\Resources\WebhookTransfers\Pages\ListWebhookTransfers;
use App\Filament\Resources\WebhookTransfers\Schemas\WebhookTransferForm;
use App\Filament\Resources\WebhookTransfers\Tables\WebhookTransfersTable;
use App\Models\WebhookTransfer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WebhookTransferResource extends Resource
{
    protected static ?string $model = WebhookTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Relay';

    protected static ?string $navigationLabel = 'Webhook Transfer';

    protected static ?string $modelLabel = 'webhook transfer';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return WebhookTransferForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WebhookTransfersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookTransfers::route('/'),
            'create' => CreateWebhookTransfer::route('/create'),
            'edit' => EditWebhookTransfer::route('/{record}/edit'),
        ];
    }
}
