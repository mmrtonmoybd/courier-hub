<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\RelayResult;
use App\DataTransferObjects\TenantMatch;
use App\DataTransferObjects\WebhookIdentity;
use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use App\Enums\MatchKey;
use App\Models\WebhookDelivery;
use App\Models\WebhookTransfer;
use Illuminate\Support\Collection;

/**
 * Orchestrates one inbound courier webhook: capture, identify, resolve the
 * owning tenant database(s), forward the untouched request to every mapped
 * target, and log each attempt. Runs inline; no queue is involved.
 */
final class WebhookRelayService
{
    public function __construct(
        private readonly CourierIdentityExtractor $extractor,
        private readonly TenantResolver $resolver,
        private readonly WebhookForwarder $forwarder,
        private readonly RetryScheduler $retryScheduler,
    ) {}

    /**
     * Shared inbound URL: the owning site is discovered from the payload and
     * every matching target is forwarded to.
     *
     * @param  array<string, mixed>  $headers
     * @param  array<array-key, mixed>  $payload
     */
    public function relay(Courier $courier, string $incomingPath, array $headers, string $rawBody, array $payload): RelayResult
    {
        $identity = $this->extractor->extract($courier, $payload);
        $matches = $this->resolver->resolve($courier, $identity);

        if ($matches === []) {
            return new RelayResult(
                collect([$this->recordUnmatched($courier, $incomingPath, $headers, $rawBody, $payload, $identity)]),
            );
        }

        $deliveries = $this->forwardToMatches($courier, $incomingPath, $headers, $rawBody, $payload, $identity, $matches);

        return new RelayResult(
            $deliveries,
            $deliveries->map(fn (WebhookDelivery $delivery): ?string => $delivery->webhookTransfer?->ack_secret)
                ->first(fn (?string $secret): bool => filled($secret)),
        );
    }

    /**
     * Site-scoped inbound URL: the site is known from its token, so the request
     * is forwarded straight to it and acknowledged with that site's secret.
     *
     * @param  array<string, mixed>  $headers
     * @param  array<array-key, mixed>  $payload
     */
    public function relayToTransfer(WebhookTransfer $transfer, string $incomingPath, array $headers, string $rawBody, array $payload): RelayResult
    {
        $courier = $transfer->courier;
        $identity = $this->extractor->extract($courier, $payload);
        $forwardPath = $this->canonicalPath($courier);

        $delivery = $this->createDelivery(
            courier: $courier,
            incomingPath: $incomingPath,
            forwardPath: $forwardPath,
            headers: $headers,
            rawBody: $rawBody,
            payload: $payload,
            identity: $identity,
            transfer: $transfer,
            matchedBy: MatchKey::Token,
        );

        $this->forwarder->forward($delivery);

        return new RelayResult(collect([$delivery]), $transfer->ack_secret);
    }

    /**
     * Re-resolve a delivery that found no owner when it arrived: couriers can
     * fire before the order row exists in the tenant database.
     */
    public function retryUnmatched(WebhookDelivery $delivery): bool
    {
        $courier = $delivery->courier;
        $identity = new WebhookIdentity(
            invoiceNo: $delivery->invoice_no,
            trackingId: $delivery->tracking_id,
            phone: $delivery->phone,
            name: $delivery->name,
        );

        $matches = $this->resolver->resolve($courier, $identity, useCache: false);

        if ($matches === []) {
            $delivery->attempts = $delivery->attempts + 1;
            $delivery->next_attempt_at = $this->retryScheduler->nextAttemptAt(
                $delivery->attempts,
                (array) config('couriers.retry.unmatched.backoff_minutes', [1]),
            );
            $delivery->save();

            return false;
        }

        $transfers = $this->transfersFor($courier, $matches);
        $first = $transfers->shift();

        if ($first === null) {
            return false;
        }

        /** @var TenantMatch $firstMatch */
        $firstMatch = collect($matches)
            ->first(fn (TenantMatch $match): bool => $match->databaseName === $first->database_name) ?? $matches[0];

        $delivery->fill([
            'database_name' => $first->database_name,
            'matched_by' => $firstMatch->matchedBy->value,
            'webhook_transfer_id' => $first->getKey(),
            'target_url' => $first->forwardUrl((string) $delivery->forward_path),
        ]);
        $delivery->setRelation('webhookTransfer', $first);
        $delivery->save();

        $this->forwarder->forward($delivery);

        foreach ($transfers as $transfer) {
            /** @var TenantMatch $match */
            $match = collect($matches)
                ->first(fn (TenantMatch $candidate): bool => $candidate->databaseName === $transfer->database_name) ?? $firstMatch;

            $clone = $this->createDelivery(
                courier: $courier,
                incomingPath: (string) $delivery->incoming_path,
                forwardPath: (string) $delivery->forward_path,
                headers: (array) $delivery->headers,
                rawBody: (string) $delivery->raw_body,
                payload: (array) $delivery->payload,
                identity: $identity,
                transfer: $transfer,
                matchedBy: $match->matchedBy,
            );

            $this->forwarder->forward($clone);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<array-key, mixed>  $payload
     * @param  array<int, TenantMatch>  $matches
     * @return Collection<int, WebhookDelivery>
     */
    private function forwardToMatches(
        Courier $courier,
        string $incomingPath,
        array $headers,
        string $rawBody,
        array $payload,
        WebhookIdentity $identity,
        array $matches,
    ): Collection {
        $deliveries = collect();

        foreach ($matches as $match) {
            foreach ($this->activeTransfers($courier, $match->databaseName) as $transfer) {
                $delivery = $this->createDelivery(
                    courier: $courier,
                    incomingPath: $incomingPath,
                    forwardPath: $incomingPath,
                    headers: $headers,
                    rawBody: $rawBody,
                    payload: $payload,
                    identity: $identity,
                    transfer: $transfer,
                    matchedBy: $match->matchedBy,
                );

                $this->forwarder->forward($delivery);
                $deliveries->push($delivery);
            }
        }

        return $deliveries;
    }

    /**
     * @param  array<int, TenantMatch>  $matches
     * @return Collection<int, WebhookTransfer>
     */
    private function transfersFor(Courier $courier, array $matches): Collection
    {
        return collect($matches)
            ->flatMap(fn (TenantMatch $match): Collection => $this->activeTransfers($courier, $match->databaseName))
            ->values();
    }

    /**
     * @return Collection<int, WebhookTransfer>
     */
    private function activeTransfers(Courier $courier, string $database): Collection
    {
        return WebhookTransfer::query()
            ->where('courier', $courier->value)
            ->where('database_name', $database)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<array-key, mixed>  $payload
     */
    private function createDelivery(
        Courier $courier,
        string $incomingPath,
        string $forwardPath,
        array $headers,
        string $rawBody,
        array $payload,
        WebhookIdentity $identity,
        WebhookTransfer $transfer,
        MatchKey $matchedBy,
    ): WebhookDelivery {
        $delivery = WebhookDelivery::query()->create([
            'courier' => $courier->value,
            'incoming_path' => $incomingPath,
            'forward_path' => $forwardPath,
            'headers' => $headers,
            'raw_body' => $rawBody,
            'payload' => $payload,
            'invoice_no' => $identity->invoiceNo,
            'tracking_id' => $identity->trackingId,
            'phone' => $identity->phone,
            'name' => $identity->name,
            'matched_by' => $matchedBy->value,
            'database_name' => $transfer->database_name,
            'webhook_transfer_id' => $transfer->getKey(),
            'target_url' => $transfer->forwardUrl($forwardPath),
            'status' => DeliveryStatus::Failed->value,
            'attempts' => 0,
        ]);

        $delivery->setRelation('webhookTransfer', $transfer);

        return $delivery;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<array-key, mixed>  $payload
     */
    private function recordUnmatched(
        Courier $courier,
        string $incomingPath,
        array $headers,
        string $rawBody,
        array $payload,
        WebhookIdentity $identity,
    ): WebhookDelivery {
        return WebhookDelivery::query()->create([
            'courier' => $courier->value,
            'incoming_path' => $incomingPath,
            'forward_path' => $incomingPath,
            'headers' => $headers,
            'raw_body' => $rawBody,
            'payload' => $payload,
            'invoice_no' => $identity->invoiceNo,
            'tracking_id' => $identity->trackingId,
            'phone' => $identity->phone,
            'name' => $identity->name,
            'status' => DeliveryStatus::Unmatched->value,
            'attempts' => 1,
            'next_attempt_at' => $this->retryScheduler->nextAttemptAt(
                1,
                (array) config('couriers.retry.unmatched.backoff_minutes', [1]),
            ),
            'last_error' => 'No mapped tenant database recognised this order.',
        ]);
    }

    private function canonicalPath(Courier $courier): string
    {
        return '/api/webhooks/'.$courier->value;
    }
}
