<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\WebhookDelivery;
use Illuminate\Support\Collection;

final readonly class RelayResult
{
    /**
     * @param  Collection<int, WebhookDelivery>  $deliveries
     */
    public function __construct(
        public Collection $deliveries,
        public ?string $ackSecret = null,
    ) {}

    public function matched(): bool
    {
        return $this->deliveries->contains(
            fn (WebhookDelivery $delivery): bool => filled($delivery->database_name),
        );
    }
}
