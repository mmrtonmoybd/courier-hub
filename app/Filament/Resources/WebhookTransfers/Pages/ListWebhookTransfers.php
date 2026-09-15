<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookTransfers\Pages;

use App\Enums\Courier;
use App\Filament\Resources\WebhookTransfers\Schemas\WebhookTransferForm;
use App\Filament\Resources\WebhookTransfers\WebhookTransferResource;
use App\Models\WebhookTransfer;
use App\Rules\HostOnlyUrl;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListWebhookTransfers extends ListRecords
{
    protected static string $resource = WebhookTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->onboardSiteAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Onboarding 10-30 sites one courier at a time is tedious, so a site can be
     * mapped to every courier it uses in a single step.
     */
    private function onboardSiteAction(): Action
    {
        return Action::make('onboardSite')
            ->label('Add site')
            ->icon('heroicon-o-building-storefront')
            ->modalHeading('Map one site to several couriers')
            ->schema([
                TextInput::make('name')
                    ->label('Site name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('target_url')
                    ->label('Site address')
                    ->placeholder('https://shop.example.com')
                    ->helperText('Scheme and host only.')
                    ->required()
                    ->rules([new HostOnlyUrl]),
                Select::make('database_name')
                    ->label('Tenant database')
                    ->options(fn (): array => WebhookTransferForm::databaseOptions())
                    ->searchable()
                    ->required(),
                Repeater::make('couriers')
                    ->label('Couriers used by this site')
                    ->schema([
                        Select::make('courier')
                            ->options(Courier::options())
                            ->required()
                            ->distinct()
                            ->native(false),
                        TextInput::make('ack_secret')
                            ->label('Secret for this site at this courier')
                            ->helperText('Optional. Falls back to the courier-wide secret.'),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Add courier'),
            ])
            ->action(function (array $data): void {
                $created = 0;

                foreach ($data['couriers'] ?? [] as $row) {
                    if (blank($row['courier'] ?? null)) {
                        continue;
                    }

                    WebhookTransfer::query()->updateOrCreate(
                        [
                            'courier' => $row['courier'],
                            'database_name' => $data['database_name'],
                            'target_url' => rtrim((string) $data['target_url'], '/'),
                        ],
                        [
                            'name' => $data['name'],
                            'ack_secret' => $row['ack_secret'] ?? null,
                            'is_active' => true,
                        ],
                    );

                    $created++;
                }

                Notification::make()
                    ->title("Mapped {$created} courier(s) for {$data['name']}.")
                    ->success()
                    ->send();
            });
    }
}
