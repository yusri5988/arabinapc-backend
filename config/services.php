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

    'claude' => [
        'api_key' => env('CLAUDE_API_KEY'),
        'endpoint' => env('CLAUDE_API_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
        'model' => env('CLAUDE_API_MODEL', 'claude-3-haiku-20240307'),
    ],

    'google_sheets' => [
        'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),
        'tab_name' => env('GOOGLE_SHEETS_TAB_NAME', 'MainData-PC System'),
        'header_row' => (int) env('GOOGLE_SHEETS_HEADER_ROW', 1),
        'enabled' => filter_var(env('GOOGLE_SHEETS_SYNC_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'max_rows' => (int) env('GOOGLE_SHEETS_MAX_ROWS', 5000),
    ],

];
