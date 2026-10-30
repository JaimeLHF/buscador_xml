<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'horizon' => [
        'admins' => env('HORIZON_ADMINS', ''),
    ],

    // Painel /admin (conexões da API): só os e-mails desta lista, separados por vírgula.
    'admin' => [
        'emails' => env('ADMIN_EMAILS', ''),
    ],

    // Notas antigas (fora dos ~90 dias da SEFAZ): busca paga por chave no acervo do Meu Danfe (R$ 0,03 por consulta).
    'meudanfe' => [
        'url' => env('MEU_DANFE_API_URL', 'https://api.meudanfe.com.br/v2'),
        'key' => env('MEU_DANFE_API_KEY'),
        // A mesma chave não pode ser consultada mais de uma vez por segundo, ou a conta é bloqueada.
        'intervalo_segundos' => 2,
        'max_consultas_por_chave' => 15,
    ],

];
