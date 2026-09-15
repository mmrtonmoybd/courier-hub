<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CourierSettings\CourierSettingResource;
use App\Filament\Resources\WebhookDeliveries\WebhookDeliveryResource;
use App\Filament\Resources\WebhookTransfers\WebhookTransferResource;
use Awcodes\Overlook\OverlookPlugin;
use Caresome\FilamentAuthDesigner\AuthDesignerPlugin;
use Caresome\FilamentAuthDesigner\Enums\MediaPosition;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Jeffgreco13\FilamentBreezy\BreezyCore;
use Northwestern\FilamentTheme\NorthwesternTheme;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->spa()
            ->spaUrlExceptions([
                Dashboard::class,
            ])
            ->login()
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('16rem')
            ->maxContentWidth(Width::Full)
            ->databaseTransactions()
            ->defaultThemeMode(ThemeMode::Light)
            ->colors([
                'primary' => Color::Orange,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->globalSearch()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchFieldKeyBindingSuffix()
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->navigationGroups([
                NavigationGroup::make()
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->label('Relay'),

                NavigationGroup::make()
                    ->collapsed(true)
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->label('Setting & Configuration'),
            ])
            ->plugins([
                NorthwesternTheme::make()->withoutAssetRegistration(),
                AuthDesignerPlugin::make()
                    ->login(fn ($config) => $config
                        ->media(asset('images/login.png'))
                        ->mediaPosition(MediaPosition::Cover)
                    ),
                BreezyCore::make()
                    ->myProfile(
                        hasAvatars: true,
                        slug: 'profile',
                        userMenuLabel: 'Profile',
                    )
                    ->enableBrowserSessions(),
                OverlookPlugin::make()
                    ->sort(2)
                    ->columns([
                        'default' => 4,
                        'sm' => 2,
                        'lg' => 4,
                        'xl' => 6,
                    ])
                    ->includes([
                        WebhookTransferResource::class,
                        WebhookDeliveryResource::class,
                        CourierSettingResource::class,
                    ]),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css');
    }
}
