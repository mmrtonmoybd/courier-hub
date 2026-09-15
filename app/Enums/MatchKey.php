<?php

declare(strict_types=1);

namespace App\Enums;

enum MatchKey: string
{
    case Invoice = 'invoice';
    case Tracking = 'tracking';
    case Phone = 'phone';
    case Name = 'name';

    /**
     * The courier hit a site-scoped inbound URL, so no lookup was needed.
     */
    case Token = 'token';
}
