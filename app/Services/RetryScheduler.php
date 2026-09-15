<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Turns an attempt count into the next sweep time. The last configured step
 * repeats for every later attempt.
 */
final class RetryScheduler
{
    /**
     * @param  array<int, int>  $backoffMinutes
     */
    public function nextAttemptAt(int $attempts, array $backoffMinutes): CarbonImmutable
    {
        $steps = array_values(array_filter($backoffMinutes, static fn (mixed $minutes): bool => is_int($minutes) && $minutes > 0));

        if ($steps === []) {
            $steps = [1];
        }

        $index = max(0, min($attempts - 1, count($steps) - 1));

        return CarbonImmutable::now()->addMinutes($steps[$index]);
    }
}
