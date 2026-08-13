<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Natural Speaking Chunk Engine Configuration
    |--------------------------------------------------------------------------
    */

    'segmentation' => [
        'preferred_min_duration_ms' => env('SHADOWING_MIN_DURATION_MS', 2500),
        'preferred_max_duration_ms' => env('SHADOWING_MAX_DURATION_MS', 6500),
        'short_chunk_threshold_ms'  => env('SHADOWING_SHORT_THRESHOLD_MS', 2200),
        'long_chunk_threshold_ms'   => env('SHADOWING_LONG_THRESHOLD_MS', 7500),
        'pause_split_threshold_ms'  => env('SHADOWING_PAUSE_SPLIT_MS', 350),

        'default_stt_provider'     => env('SHADOWING_STT_PROVIDER', 'deepgram'),
        'deepgram_utt_split'       => env('DEEPGRAM_UTT_SPLIT', '0.8'),
    ],

    'clause_markers' => [
        'because', 'but', 'so', 'although', 'when', 'while', 'if', 'which',
        'who', 'that', 'to be honest', 'in my opinion', 'for example',
        'especially', 'actually', 'however',
    ],

    'processing_version' => 'natural-chunk-v1',

    /*
    |--------------------------------------------------------------------------
    | YouTube Caption Proxy URL
    |--------------------------------------------------------------------------
    | HTTP proxy running on the host machine to bypass YouTube's cloud IP blocking.
    | Default: http://host.docker.internal:9876 (accessible from Docker containers)
    | Start proxy: python3 app/Modules/Shadowing/Infrastructure/Scripts/yt_caption_proxy.py
    */
    'caption_proxy_url' => env('YT_CAPTION_PROXY_URL', 'http://host.docker.internal:9876'),

    'pro_quota_minutes_per_month' => env('SHADOWING_PRO_QUOTA_MINUTES', 300),

    'transcript_priority' => [
        'youtube_manual_caption',
        'youtube_auto_caption',
        'deepgram_nova3',
    ],

    'bad_ending_words' => [
        // Prepositions
        'to', 'of', 'on', 'in', 'for', 'with', 'by', 'from', 'at', 'about', 'into',
        // Conjunctions
        'and', 'but', 'because', 'although', 'if', 'when', 'while', 'that', 'so', 'or',
        // Pronouns & Subjects
        'i', 'you', 'he', 'she', 'they', 'we', 'it', 'this',
        // Auxiliaries & Verbs
        'is', 'are', 'was', 'were', 'have', 'has', 'do', 'does', 'can', 'could', 'will', 'would',
        // Articles
        'a', 'an', 'the',
    ],
];
