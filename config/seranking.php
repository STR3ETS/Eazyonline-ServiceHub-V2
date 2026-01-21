<?php

return [
    /**
     * Project API (api4) - voor projecten, keywords, rankings, stat.
     * Let op: Project API key is anders dan Data API key.
     */
    'project_api_key' => env('SERANKING_PROJECT_API_KEY'),
    'project_base_url' => env('SERANKING_PROJECT_BASE_URL', 'https://api4.seranking.com'),

    /**
     * Data API (api.seranking.com) - alleen als je het later nodig hebt.
     * (Je kunt voorlopig zonder Data API, want positions geeft al volume etc.)
     */
    'data_api_key' => env('SERANKING_DATA_API_KEY'),
    'data_base_url' => env('SERANKING_DATA_BASE_URL', 'https://api.seranking.com'),

    /**
     * Site Audit API draait bij jou al via api.seranking.com/v1/site-audit/...
     */
    'site_audit_base_url' => env('SERANKING_SITE_AUDIT_BASE_URL', 'https://api.seranking.com'),

    /**
     * Default site_engine_id (in de praktijk halen we hem live op via /search-engines,
     * maar dit is een fallback).
     */
    'default_site_engine_id' => (int) env('SERANKING_DEFAULT_SITE_ENGINE_ID', 1),

    'default_audit_settings' => [
        'source_site'      => 1,
        'source_sitemap'   => 1,
        'source_subdomain' => 0,
        'source_file'      => 0,
        'check_robots'     => 1,
        'ignore_params'    => 0,
        'custom_params'    => 'utm_source,utm_medium,utm_term,utm_content,utm_campaign',
        'ignore_noindex'   => 0,
        'ignore_nofollow'  => 0,
        'user_agent'       => 0,
        'max_pages'        => 1000,
        'max_depth'        => 10,
        'max_req'          => 100,
        'max_redirects'    => 5,
        'min_title_len'    => 20,
        'max_title_len'    => 65,
        'min_description_len' => 1,
        'max_description_len' => 158,
        'max_size'         => 3000,
        'min_words'        => 250,
        'max_h1_len'       => 100,
        'max_h2_len'       => 100,
    ],
];
