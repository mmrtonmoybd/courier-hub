<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Courier;
use App\Models\WebhookTransfer;
use App\Services\CourierResponder;
use App\Services\WebhookRelayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Entry point for every courier webhook. The courier always receives the
 * canned acknowledgement for its own protocol, whatever happened downstream.
 */
final class IncomingWebhookController extends Controller
{
    public function __construct(
        private readonly WebhookRelayService $relay,
        private readonly CourierResponder $responder,
    ) {}

    /**
     * Shared inbound URL: the owning site is discovered from the payload.
     */
    public function handle(Request $request, string $courier): Response|JsonResponse
    {
        $courierEnum = Courier::tryFrom($courier);

        if ($courierEnum === null) {
            throw new NotFoundHttpException('Unknown courier.');
        }

        $result = $this->relay->relay(
            $courierEnum,
            $this->incomingPath($request),
            $this->headers($request),
            $request->getContent(),
            $this->payload($request),
        );

        return $this->responder->respond($courierEnum, $result->ackSecret);
    }

    /**
     * Site-scoped inbound URL: used when a site has its own merchant account,
     * and therefore its own webhook secret, at the courier.
     */
    public function handleForSite(Request $request, string $token, string $courier): Response|JsonResponse
    {
        $courierEnum = Courier::tryFrom($courier);

        if ($courierEnum === null) {
            throw new NotFoundHttpException('Unknown courier.');
        }

        $transfer = WebhookTransfer::query()
            ->where('inbound_token', $token)
            ->where('courier', $courierEnum->value)
            ->where('is_active', true)
            ->first();

        if (! $transfer instanceof WebhookTransfer) {
            throw new NotFoundHttpException('Unknown webhook token.');
        }

        $result = $this->relay->relayToTransfer(
            $transfer,
            $this->incomingPath($request),
            $this->headers($request),
            $request->getContent(),
            $this->payload($request),
        );

        return $this->responder->respond($courierEnum, $result->ackSecret);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function payload(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return $request->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(Request $request): array
    {
        return $request->headers->all();
    }

    private function incomingPath(Request $request): string
    {
        return '/'.ltrim($request->path(), '/');
    }
}
