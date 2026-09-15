<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryStatus: string
{
    case Unmatched = 'unmatched';
    case Delivered = 'delivered';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unmatched => 'Unmatched',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
