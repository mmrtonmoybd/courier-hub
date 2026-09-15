<?php

declare(strict_types=1);

use App\Rules\HostOnlyUrl;
use Illuminate\Support\Facades\Validator;

function validateTargetUrl(mixed $value): array
{
    $validator = Validator::make(['target_url' => $value], ['target_url' => [new HostOnlyUrl]]);

    return $validator->errors()->get('target_url');
}

it('accepts a bare site address', function (string $url): void {
    expect(validateTargetUrl($url))->toBeEmpty();
})->with([
    'https://elegantemart.com',
    'http://elegantemart.com',
    'https://shop.elegantemart.com',
    'https://elegantemart.com/',
    'http://127.0.0.1:8000',
    'https://elegantemart.com:8443',
]);

it('rejects anything more than a site address', function (mixed $url): void {
    expect(validateTargetUrl($url))->not->toBeEmpty();
})->with([
    'with a path' => 'https://elegantemart.com/api/webhooks/pathao',
    'with a short path' => 'https://elegantemart.com/shop',
    'with a query string' => 'https://elegantemart.com?token=abc',
    'with a fragment' => 'https://elegantemart.com#section',
    'with credentials' => 'https://user:pass@elegantemart.com',
    'without a scheme' => 'elegantemart.com',
    'with the wrong scheme' => 'ftp://elegantemart.com',
    'not a string' => 12345,
]);
