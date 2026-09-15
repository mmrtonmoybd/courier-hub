<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Courier;
use App\Services\TenantRouteCache;
use Database\Factories\WebhookTransferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class WebhookTransfer extends Model
{
    /** @use HasFactory<WebhookTransferFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'target_url',
        'courier',
        'database_name',
        'ack_secret',
        'inbound_token',
        'priority',
        'is_active',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $transfer): void {
            if (blank($transfer->inbound_token)) {
                $transfer->inbound_token = (string) Str::uuid();
            }
        });

        self::saved(fn () => app(TenantRouteCache::class)->flush());
        self::deleted(fn () => app(TenantRouteCache::class)->flush());
    }

    /**
     * The URL a courier is pointed at when this site has its own merchant
     * account, and therefore its own webhook secret, at that courier.
     */
    public function inboundUrl(): string
    {
        return url('/api/w/'.$this->inbound_token.'/webhooks/'.$this->courier->value);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * The absolute URL an inbound webhook path forwards to on this target.
     */
    public function forwardUrl(string $incomingPath): string
    {
        return rtrim($this->target_url, '/').'/'.ltrim($incomingPath, '/');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'courier' => Courier::class,
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
