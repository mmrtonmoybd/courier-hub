<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\WebhookIdentity;
use App\Enums\Courier;
use Illuminate\Support\Arr;

/**
 * Pulls the order identity out of a courier webhook payload using the field
 * map in `config/couriers.php`. A missing key never throws, it yields null.
 */
final class CourierIdentityExtractor
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function extract(Courier $courier, array $payload): WebhookIdentity
    {
        /** @var array<string, array<int, string>> $fields */
        $fields = (array) config("couriers.{$courier->value}.fields", []);

        return new WebhookIdentity(
            invoiceNo: $this->firstValue($payload, $fields['invoice'] ?? []),
            trackingId: $this->firstValue($payload, $fields['tracking'] ?? []),
            phone: $this->firstValue($payload, $fields['phone'] ?? []),
            name: $this->firstValue($payload, $fields['name'] ?? []),
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<int, string>  $paths
     */
    private function firstValue(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);

            if (is_array($value) || is_object($value) || ! filled($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
