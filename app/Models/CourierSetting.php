<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Courier;
use Database\Factories\CourierSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class CourierSetting extends Model
{
    /** @use HasFactory<CourierSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'courier',
        'secret',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'courier' => Courier::class,
            'is_active' => 'boolean',
        ];
    }
}
