<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookTransfers\Pages;

use App\Filament\Resources\WebhookTransfers\WebhookTransferResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWebhookTransfer extends CreateRecord
{
    protected static string $resource = WebhookTransferResource::class;
}
