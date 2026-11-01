<?php

return [
    'hwid_async_metadata' => (bool) env('SUBSCRIPTION_HWID_ASYNC_METADATA', false),

    'hwid_observation_worker_enabled' => (bool) env('SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED', false),

    'hwid_observation_publish_connection' => 'hwid_observation_publish',

    'hwid_observation_worker_connection' => 'hwid_observation_worker',

    'hwid_observation_queue' => 'hwid_observation',

    'hwid_observation_job_timeout' => 45,

    'hwid_observation_job_tries' => 3,

    'hwid_observation_job_backoff' => [5, 30],

    'hwid_observation_retry_after' => 90,

    'hwid_observation_alert_oldest_ready_seconds' => (int) env('SUBSCRIPTION_HWID_ALERT_OLDEST_READY_SECONDS', 60),

    'hwid_observation_alert_max_backlog' => (int) env('SUBSCRIPTION_HWID_ALERT_MAX_BACKLOG', 10000),

    'hwid_observation_failed_retention_days' => 7,

    'hwid_observation_failed_prune_batch_size' => 100,

    'hwid_observation_failed_prune_max_batches' => 10,

    'timing_log_enabled' => (bool) env('SUBSCRIPTION_TIMING_LOG_ENABLED', true),

    'timing_log_channel' => (string) env('SUBSCRIPTION_TIMING_LOG_CHANNEL', 'daily'),

    'timing_sample_rate' => (float) env('SUBSCRIPTION_TIMING_SAMPLE_RATE', 0.01),

    'timing_slow_threshold_ms' => (float) env('SUBSCRIPTION_TIMING_SLOW_THRESHOLD_MS', 1000),

    'timing_correlation_header' => (string) env('SUBSCRIPTION_TIMING_CORRELATION_HEADER', 'X-Request-Id'),
];
