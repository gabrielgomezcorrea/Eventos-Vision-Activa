<?php

/*
 * Spaced sending of certificates (24/11/2026). Production sends through a
 * Gmail account: a burst of emails with attachments gets it throttled or sent
 * to spam, so they leave one by one with a random gap and a daily cap that
 * counts every event (the limit belongs to the account, not to the event).
 */
return [
    'gap_min_seconds' => (int) env('CERTIFICATES_GAP_MIN_SECONDS', 180),
    'gap_max_seconds' => (int) env('CERTIFICATES_GAP_MAX_SECONDS', 360),
    'daily_limit' => (int) env('CERTIFICATES_DAILY_LIMIT', 150),

    // This many failures in a row pause the sending: something is wrong with
    // the mail account and insisting only makes it worse.
    'failures_to_pause' => 3,

    // A certificate stuck in "sending" longer than this is marked failed: the
    // worker died mid-send and nobody knows if it left.
    'sending_timeout_minutes' => 15,

    'max_file_kb' => 5120,
    // Below PHP's default max_file_uploads (20): the screen uploads in batches.
    'files_per_request' => 10,
];
