<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'key' => env('CLAUDE_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-haiku-5-5'),
        'url' => env('CLAUDE_API_URL', 'https://api.anthropic.com/v1/messages'),
        'version' => '2023-06-01',
        'max_tokens' => (int) env('CLAUDE_MAX_TOKENS', 4096),
    ],

    'youtrack' => [
        'url' => rtrim((string) env('YOUTRACK_URL'), '/'),
        'token' => env('YOUTRACK_TOKEN'),
        // Hub OAuth login (register a Service in YouTrack > Administration > Hub > Services)
        'hub_url' => rtrim((string) env('YOUTRACK_HUB_URL', env('YOUTRACK_URL').'/hub'), '/'),
        'client_id' => env('YOUTRACK_CLIENT_ID'),
        'client_secret' => env('YOUTRACK_CLIENT_SECRET'),
        'redirect' => env('YOUTRACK_REDIRECT_URI'),
        // Hub's own service id: enough to read the profile of whoever logs in
        'scopes' => env('YOUTRACK_SCOPES', '0-0-0-0-0'),
        // Local development only: lets you log in without Hub (never honoured outside APP_ENV=local)
        'dev_login' => (bool) env('AUTH_DEV_LOGIN', false),
        // Always put in the title of issues created by the app (set empty to disable).
        'title_prefix' => env('YOUTRACK_TITLE_PREFIX', 'IssueTesteFabio'),
    ],

];
