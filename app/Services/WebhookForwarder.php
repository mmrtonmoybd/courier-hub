<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Replays a captured courier webhook against a tenant ecommerce application on
 * the very same path the courier used, so the tenant cannot tell it was
 * relayed. Runs inline during the request, no queue involved.
 */
final class WebhookForwarder
{
    public function __construct(private readonly RetryScheduler $retryScheduler) {}

    public function forward(WebhookDelivery $delivery): bool
    {
        $delivery->attempts = $delivery->attempts + 1;

        try {
            $response = Http::withHeaders($this->forwardableHeaders($delivery))
                ->timeout((int) config('couriers.forwarding.timeout', 10))
                ->withBody((string) $delivery->raw_body, $this->contentType($delivery))
                ->post((string) $delivery->target_url);

            $delivery->response_status = $response->status();
            $delivery->response_body = $this->truncate($response->body());

            if ($response->successful()) {
                $delivery->status = DeliveryStatus::Delivered;
                $delivery->delivered_at = CarbonImmutable::now();
                $delivery->next_attempt_at = null;
                $delivery->last_error = null;
                $delivery->save();

                return true;
            }

            $delivery->last_error = 'HTTP '.$response->status();
        } catch (Throwable $exception) {
            $delivery->response_status = null;
            $delivery->response_body = null;
            $delivery->last_error = $exception->getMessage();
        }

        $delivery->status = DeliveryStatus::Failed;
        $delivery->next_attempt_at = $this->retryScheduler->nextAttemptAt(
            $delivery->attempts,
            (array) config('couriers.retry.backoff_minutes', [1]),
        );
        $delivery->save();

        return false;
    }

    /**
     * The courier's own headers are passed through so the tenant sees the
     * original signature headers; hop-by-hop headers are dropped.
     *
     * @return array<string, string>
     */
    private function forwardableHeaders(WebhookDelivery $delivery): array
    {
        /** @var array<int, string> $strip */
        $strip = (array) config('couriers.forwarding.strip_headers', ['host', 'content-length']);
        $headers = [];

        foreach ((array) $delivery->headers as $name => $value) {
            if (in_array(mb_strtolower((string) $name), $strip, true)) {
                continue;
            }

            $headers[(string) $name] = is_array($value)
                ? (string) ($value[0] ?? '')
                : (string) $value;
        }

        return $headers;
    }

    private function contentType(WebhookDelivery $delivery): string
    {
        foreach ((array) $delivery->headers as $name => $value) {
            if (mb_strtolower((string) $name) !== 'content-type') {
                continue;
            }

            $contentType = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;

            if ($contentType !== '') {
                return $contentType;
            }
        }

        return 'application/json';
    }

    private function truncate(string $body): string
    {
        return mb_substr($body, 0, (int) config('couriers.forwarding.response_body_limit', 2000));
    }
}
