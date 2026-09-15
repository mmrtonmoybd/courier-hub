<?php

declare(strict_types=1);

use App\Http\Controllers\IncomingWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Inbound courier webhooks. The paths mirror the ecommerce applications exactly
| so a courier configuration can be repointed here without the tenant noticing.
*/

Route::post('/webhooks/{courier}', [IncomingWebhookController::class, 'handle'])
    ->name('webhooks.handle');

Route::post('/pathao-webhook', [IncomingWebhookController::class, 'handle'])
    ->defaults('courier', 'pathao')
    ->name('webhooks.pathao.legacy');

Route::post('/steadfast-webhook', [IncomingWebhookController::class, 'handle'])
    ->defaults('courier', 'steadfast')
    ->name('webhooks.steadfast.legacy');

/*
| Site-scoped inbound URL, for sites that hold their own merchant account (and
| therefore their own secret) at a courier.
*/

Route::post('/w/{token}/webhooks/{courier}', [IncomingWebhookController::class, 'handleForSite'])
    ->name('webhooks.site');
