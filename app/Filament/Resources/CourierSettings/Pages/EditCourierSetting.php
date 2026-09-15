<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourierSettings\Pages;

use App\Filament\Resources\CourierSettings\CourierSettingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCourierSetting extends EditRecord
{
    protected static string $resource = CourierSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
