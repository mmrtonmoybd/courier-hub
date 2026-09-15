<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| Driven by the existing `php artisan schedule:run` cron entry; the relay uses
| no queue workers.
*/

Schedule::command('webhooks:retry')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('webhooks:prune')
    ->dailyAt('03:10');
