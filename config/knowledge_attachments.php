<?php

return [
    'disk' => env('KNOWLEDGE_ATTACHMENT_DISK', 'knowledge_attachments'),
    'chunk_size_bytes' => (int) env('KNOWLEDGE_ATTACHMENT_CHUNK_SIZE', 5 * 1024 * 1024),
    'max_file_size_bytes' => (int) env('KNOWLEDGE_ATTACHMENT_MAX_FILE_SIZE', 1024 * 1024 * 1024),
    'total_quota_bytes' => (int) env('KNOWLEDGE_ATTACHMENT_TOTAL_QUOTA', 20 * 1024 * 1024 * 1024),
    'signed_url_ttl_minutes' => (int) env('KNOWLEDGE_ATTACHMENT_SIGNED_URL_TTL', 120),
    'draft_ttl_hours' => (int) env('KNOWLEDGE_ATTACHMENT_DRAFT_TTL', 24),
    'trash_retention_days' => (int) env('KNOWLEDGE_ATTACHMENT_TRASH_RETENTION', 7),
    'directories' => [
        'files' => 'files',
        'temporary' => 'temporary',
        'quarantine' => 'quarantine',
    ],
    'inline_image_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ],
    'inline_video_mime_types' => [
        'video/mp4',
        'video/webm',
        'video/ogg',
    ],
];

