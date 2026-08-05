<?php

return [
    'base_url' => rtrim((string) env('BOOKSTACK_BASE_URL', ''), '/'),
    'token_id' => (string) env('BOOKSTACK_TOKEN_ID', ''),
    'token_secret' => (string) env('BOOKSTACK_TOKEN_SECRET', ''),
    'book_id' => (int) env('BOOKSTACK_BOOK_ID', 0),
    'timeout' => (int) env('BOOKSTACK_TIMEOUT', 15),
];
