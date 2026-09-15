<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookTransfers\Pages;

use App\Filament\Resources\WebhookTransfers\WebhookTransferResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWebhookTransfer extends EditRecord
{
    protected static string $resource = WebhookTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
