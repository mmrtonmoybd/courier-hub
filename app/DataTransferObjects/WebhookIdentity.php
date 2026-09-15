<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

final readonly class WebhookIdentity
{
    public function __construct(
        public ?string $invoiceNo = null,
        public ?string $trackingId = null,
        public ?string $phone = null,
        public ?string $name = null,
    ) {}

    /**
     * Whether the payload carried any of the reliable order keys. The customer
     * name is only consulted when none of these were present.
     */
    public function hasStrongKey(): bool
    {
        return filled($this->invoiceNo) || filled($this->trackingId) || filled($this->phone);
    }

    public function isEmpty(): bool
    {
        return ! $this->hasStrongKey() && blank($this->name);
    }

    /**
     * @return array{invoice_no: ?string, tracking_id: ?string, phone: ?string, name: ?string}
     */
    public function toArray(): array
    {
        return [
            'invoice_no' => $this->invoiceNo,
            'tracking_id' => $this->trackingId,
            'phone' => $this->phone,
            'name' => $this->name,
        ];
    }
}
