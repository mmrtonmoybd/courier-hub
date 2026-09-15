<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Courier Webhook Maps
|--------------------------------------------------------------------------
|
| One entry per supported courier. `fields` describes where the identity of
| an order lives inside that courier's webhook payload; dot notation is
| supported for nested payloads (Paperfly wraps everything in `data`).
| Candidates are read in order and the first non-empty value wins.
|
| `ack` is the canned response the relay returns to the courier. The relay
| never proxies the tenant's response back to the courier. `header` is sent
| with the value stored in `courier_settings.secret`, falling back to
| `default_secret`.
|
*/

return [

    'pathao' => [
        'label' => 'Pathao',
        'fields' => [
            'invoice' => ['merchant_order_id'],
            'tracking' => ['consignment_id'],
            'phone' => ['recipient_phone'],
            'name' => ['recipient_name'],
        ],
        'ack' => [
            'status' => 202,
            'body' => 'OK',
            'json' => null,
            'header' => 'X-Pathao-Merchant-Webhook-Integration-Secret',
            'default_secret' => 'f3992ecc-59da-4cbe-a049-a13da2018d51',
        ],
    ],

    'steadfast' => [
        'label' => 'Steadfast',
        'fields' => [
            'invoice' => ['invoice'],
            'tracking' => ['tracking_code', 'consignment_id'],
            'phone' => ['recipient_phone'],
            'name' => ['recipient_name'],
        ],
        'ack' => [
            'status' => 200,
            'body' => null,
            'json' => [
                'status' => 'success',
                'message' => 'Webhook received successfully.',
            ],
            'header' => null,
            'default_secret' => 'a13da2018d51',
        ],
    ],

    'carrybee' => [
        'label' => 'Carrybee',
        'fields' => [
            'invoice' => ['merchant_order_id'],
            'tracking' => ['consignment_id'],
            'phone' => ['recipient_phone'],
            'name' => ['recipient_name'],
        ],
        'ack' => [
            'status' => 202,
            'body' => null,
            'json' => [
                'message' => 'Event received',
            ],
            'header' => 'X-CB-Webhook-Integration-Header',
            'default_secret' => '40489fe0-9386-4fc9-8e92-2b2fcb9d451c',
        ],
    ],

    'paperfly' => [
        'label' => 'Paperfly',
        'fields' => [
            'invoice' => ['data.merchant_order_reference'],
            'tracking' => ['data.order_number', 'data.barcode'],
            'phone' => ['data.recipient_phone'],
            'name' => ['data.recipient_name'],
        ],
        'ack' => [
            'status' => 200,
            'body' => 'OK',
            'json' => null,
            'header' => null,
            'default_secret' => null,
        ],
    ],

    'redx' => [
        'label' => 'RedX',
        'fields' => [
            'invoice' => ['invoice_number'],
            'tracking' => ['tracking_number'],
            'phone' => ['customer_phone'],
            'name' => ['customer_name'],
        ],
        'ack' => [
            'status' => 200,
            'body' => 'OK',
            'json' => null,
            'header' => null,
            'default_secret' => null,
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Forwarding & retry
    |----------------------------------------------------------------------
    */

    'forwarding' => [
        'timeout' => 10,
        // Set by the HTTP client itself when the forward is made.
        'strip_headers' => ['host', 'content-length', 'content-type'],
        'response_body_limit' => 2000,
    ],

    'retention' => [
        'delivered_days' => 30,
        'other_days' => 90,
    ],

    'retry' => [
        'max_failed_attempts' => 20,
        'backoff_minutes' => [1, 2, 5, 10, 30, 60],
        'unmatched' => [
            'max_attempts' => 10,
            'window_minutes' => 30,
            'backoff_minutes' => [1],
        ],
    ],
];
