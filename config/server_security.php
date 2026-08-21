<?php

return [
    'receipt_retention_hours' => 168,
    'node_release_version' => env('XBOARD_NODE_RELEASE_VERSION', 'v1.14.0'),

    'body_limits' => [
        'handshake' => 64 * 1024,
        'control' => 2 * 1024 * 1024,
        'report' => 8 * 1024 * 1024,
    ],

    'rate_limits' => [
        'handshake' => [
            'per_ip' => 60,
            'per_peer' => 600,
            'per_credential' => 20,
        ],
        'pull' => [
            'per_ip' => 2400,
            'per_peer' => 10000,
            'per_credential' => 600,
        ],
        'report' => [
            'per_ip' => 1200,
            'per_peer' => 10000,
            'per_credential' => 240,
        ],
        'machine' => [
            'per_ip' => 1200,
            'per_peer' => 5000,
            'per_credential' => 240,
        ],
    ],

    'report_limits' => [
        'users' => 100000,
        'devices_per_user' => 64,
        'cpu_cores' => 1024,
    ],
];
