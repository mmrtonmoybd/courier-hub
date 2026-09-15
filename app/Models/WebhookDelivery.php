<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Courier;
use App\Enums\DeliveryStatus;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'courier',
        'incoming_path',
        'forward_path',
        'headers',
        'raw_body',
        'payload',
        'invoice_no',
        'tracking_id',
        'phone',
        'name',
        'matched_by',
        'database_name',
        'webhook_transfer_id',
        'target_url',
        'status',
        'attempts',
        'next_attempt_at',
        'response_status',
        'response_body',
        'last_error',
        'delivered_at',
    ];

    /**
     * @return BelongsTo<WebhookTransfer, $this>
     */
    public function webhookTransfer(): BelongsTo
    {
        return $this->belongsTo(WebhookTransfer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'courier' => Courier::class,
            'status' => DeliveryStatus::class,
            'headers' => 'array',
            'payload' => 'array',
            'attempts' => 'integer',
            'response_status' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
