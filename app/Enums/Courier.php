<?php

declare(strict_types=1);

namespace App\Enums;

enum Courier: string
{
    case Pathao = 'pathao';
    case Steadfast = 'steadfast';
    case Carrybee = 'carrybee';
    case Paperfly = 'paperfly';
    case Redx = 'redx';

    public function label(): string
    {
        return (string) config("couriers.{$this->value}.label", $this->name);
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
