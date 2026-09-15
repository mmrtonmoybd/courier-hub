<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Courier;
use App\Models\CourierSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Builds the acknowledgement the courier expects. Each site can hold its own
 * secret per courier (`webhook_transfers.ack_secret`); the courier-wide secret
 * in `courier_settings` is the fallback when the site is not known yet. The relay always answers
 * with its own ack, never with the tenant's response, and does so regardless of
 * whether forwarding succeeded.
 */
final class CourierResponder
{
    public function respond(Courier $courier, ?string $secretOverride = null): Response|JsonResponse
    {
        /** @var array<string, mixed> $ack */
        $ack = (array) config("couriers.{$courier->value}.ack", []);
        $status = (int) ($ack['status'] ?? 200);
        $json = $ack['json'] ?? null;

        $response = is_array($json)
            ? response()->json($json, $status)
            : response((string) ($ack['body'] ?? 'OK'), $status);

        $header = $ack['header'] ?? null;

        if (is_string($header) && $header !== '') {
            $secret = filled($secretOverride) ? $secretOverride : $this->secret($courier, $ack);
            $response->header($header, (string) $secret);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $ack
     */
    private function secret(Courier $courier, array $ack): ?string
    {
        $stored = CourierSetting::query()
            ->where('courier', $courier->value)
            ->value('secret');

        return filled($stored)
            ? (string) $stored
            : ($ack['default_secret'] ?? null);
    }
}
