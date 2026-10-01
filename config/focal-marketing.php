<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | Define the database table names used by the Focal Marketing package.
    |
    */
    'tables' => [
        'templates' => 'focal_marketing_templates',
        'forms' => 'focal_marketing_forms',
        'form_submissions' => 'focal_marketing_form_submissions',
        'campaigns' => 'focal_marketing_campaigns',
        'recipients' => 'focal_marketing_campaign_recipients',
        'subscriptions' => 'focal_marketing_subscriptions',
        'scoring_rules' => 'focal_marketing_lead_scoring_rules',
        'score_logs' => 'focal_marketing_lead_score_logs',
        'workflows' => 'focal_marketing_workflows',
        'workflow_steps' => 'focal_marketing_workflow_steps',
        'workflow_enrollments' => 'focal_marketing_workflow_enrollments',
        'workflow_logs' => 'focal_marketing_workflow_logs',
        'visitor_sessions' => 'focal_marketing_visitor_sessions',
        'page_views' => 'focal_marketing_page_views',
        'landing_pages' => 'focal_marketing_landing_pages',
        'esp_events' => 'focal_marketing_esp_events',
        'sms_messages' => 'focal_marketing_sms_messages',
        'nps_surveys' => 'focal_marketing_nps_surveys',
        'nps_responses' => 'focal_marketing_nps_responses',
        'assets' => 'focal_marketing_assets',
        'asset_downloads' => 'focal_marketing_asset_downloads',
        'events' => 'focal_marketing_events',
        'event_registrations' => 'focal_marketing_event_registrations',
        'custom_behavioral_events' => 'focal_custom_behavioral_events',
        'ad_audience_syncs' => 'focal_ad_audience_syncs',
        'subscription_topics' => 'focal_marketing_subscription_topics',
        'contact_topics' => 'focal_marketing_contact_topics',
        'suppressions' => 'focal_marketing_suppressions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sender Defaults
    |--------------------------------------------------------------------------
    |
    | Default sender identity for broadcast email campaigns.
    |
    */
    'defaults' => [
        'sender_name' => env('MARKETING_FROM_NAME', 'Focal Marketing'),
        'sender_email' => env('MARKETING_FROM_EMAIL', 'newsletter@focal.test'),
        'reply_to' => env('MARKETING_REPLY_TO', 'support@focal.test'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Send Frequency Capping & Fatigue Protection
    |--------------------------------------------------------------------------
    |
    | Limits communication frequency per contact to prevent email fatigue.
    |
    */
    'fatigue_protection' => [
        'enabled' => (bool) env('MARKETING_FATIGUE_PROTECTION_ENABLED', false),
        'max_emails_per_7_days' => (int) env('MARKETING_MAX_EMAILS_7_DAYS', 2),
        'min_hours_between_sends' => (int) env('MARKETING_MIN_HOURS_BETWEEN_SENDS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Marketing-to-Sales Instant Hand-off
    |--------------------------------------------------------------------------
    |
    | Automatically create high-priority pipeline deals and tasks when leads
    | reach MQL/SQL thresholds or submit high-intent forms.
    |
    */
    'sales_handoff' => [
        'auto_handoff_on_sql' => (bool) env('MARKETING_AUTO_HANDOFF_ON_SQL', true),
        'sql_score_threshold' => (int) env('MARKETING_SQL_THRESHOLD', 100),
        'default_deal_amount' => (float) env('MARKETING_HANDOFF_DEAL_AMOUNT', 10000.00),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Public pages (hosted forms, landing pages, tracking, unsubscribe and
    | preference center) are registered in the "web" group. Webhooks and JSON
    | APIs are registered in the "api" group. Each group accepts a domain,
    | prefix and middleware. Set "enabled" to false to register your own
    | routes instead; keep the focal.marketing.* route names, since emails
    | and models generate links from them.
    |
    */
    'routes' => [
        'enabled' => (bool) env('FOCAL_MARKETING_ROUTES_ENABLED', true),

        'web' => [
            'domain' => env('FOCAL_MARKETING_DOMAIN'),
            'prefix' => env('FOCAL_MARKETING_PREFIX', ''),
            'middleware' => ['web'],
        ],

        'api' => [
            'domain' => env('FOCAL_MARKETING_DOMAIN'),
            'prefix' => env('FOCAL_MARKETING_API_PREFIX', 'api/marketing'),
            'middleware' => ['web'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API Token
    |--------------------------------------------------------------------------
    |
    | Shared secret for this package's server-to-server endpoints (webhooks and
    | sending APIs). Send it as 'Authorization: Bearer <token>', an
    | 'X-Focal-Token' header, or a '?token=' query parameter. While empty, those
    | endpoints are disabled. Generate one with: php -r 'echo bin2hex(random_bytes(32));'
    |
    */
    'api' => [
        'token' => env('FOCAL_MARKETING_API_TOKEN'),
    ],
];
