<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\MatchKey;

final readonly class TenantMatch
{
    public function __construct(
        public string $databaseName,
        public MatchKey $matchedBy,
    ) {}
}
