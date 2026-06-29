<?php
return [
    'site_name' => 'AI SEO Site',
    'base_url' => getenv('SITE_BASE_URL') ?: 'https://example.com',
    'locale' => 'ar',
    'storage_path' => __DIR__ . '/data',
    'openrouter' => [
        'api_key' => getenv('OPENROUTER_API_KEY') ?: '',
        'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
        'free_models' => [
            'meta-llama/llama-3.3-70b-instruct:free',
            'google/gemini-2.0-flash-exp:free',
            'qwen/qwen-2.5-72b-instruct:free',
            'mistralai/mistral-7b-instruct:free',
            'openchat/openchat-7b:free',
        ],
        'timeout' => 60,
        'retries_per_model' => 1,
    ],
    'seo' => [
        'default_authority_links' => [
            'https://developers.google.com/search/docs',
            'https://schema.org',
        ],
        'internal_link_limit' => 6,
        'related_article_limit' => 6,
    ],
    'cron_token' => getenv('CRON_TOKEN') ?: 'change-me',
];
