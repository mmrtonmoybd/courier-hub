<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookDeliveries\Pages;

use App\Filament\Resources\WebhookDeliveries\WebhookDeliveryResource;
use Filament\Resources\Pages\ListRecords;

class ListWebhookDeliveries extends ListRecords
{
    protected static string $resource = WebhookDeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
