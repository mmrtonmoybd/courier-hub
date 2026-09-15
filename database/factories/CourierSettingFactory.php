<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Courier;
use App\Models\CourierSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourierSetting>
 */
final class CourierSettingFactory extends Factory
{
    protected $model = CourierSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'courier' => Courier::Pathao->value,
            'secret' => fake()->uuid(),
            'is_active' => true,
        ];
    }
}
