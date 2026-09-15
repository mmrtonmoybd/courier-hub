<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A transfer target must be a bare site address: the relay appends the
 * courier's own webhook path to it, so any path, query or fragment here would
 * produce a broken forward URL.
 */
final class HostOnlyUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The :attribute must be a URL.');

            return;
        }

        $url = rtrim(trim($value), '/');
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            $fail('The :attribute must include a scheme and host, for example https://shop.example.com.');

            return;
        }

        if (! in_array(mb_strtolower($parts['scheme']), ['http', 'https'], true)) {
            $fail('The :attribute must use http or https.');

            return;
        }

        if (filled($parts['path'] ?? null) && trim((string) $parts['path'], '/') !== '') {
            $fail('The :attribute must not contain a path. Use the site address only, for example https://shop.example.com.');

            return;
        }

        if (filled($parts['query'] ?? null) || filled($parts['fragment'] ?? null)) {
            $fail('The :attribute must not contain a query string or fragment.');

            return;
        }

        if (filled($parts['user'] ?? null) || filled($parts['pass'] ?? null)) {
            $fail('The :attribute must not contain credentials.');
        }
    }
}
