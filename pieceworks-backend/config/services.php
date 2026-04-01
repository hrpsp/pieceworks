<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bata Supply Chain API
    |--------------------------------------------------------------------------
    | endpoint   – full URL to the production data feed
    | api_key    – bearer token for Authorization header
    | timeout    – HTTP timeout in seconds
    | source_system – identifier used in worker_id_mapping
    */
    /*
    |--------------------------------------------------------------------------
    | Twilio (WhatsApp + SMS)
    |--------------------------------------------------------------------------
    | sid              – Twilio Account SID
    | token            – Twilio Auth Token
    | from             – Twilio SMS sender number (E.164, e.g. +12025551234)
    | whatsapp_from    – Twilio WhatsApp sandbox/number (whatsapp:+14155238886)
    */
    'twilio' => [
        'sid'           => env('TWILIO_SID', ''),
        'token'         => env('TWILIO_AUTH_TOKEN', ''),
        'from'          => env('TWILIO_PHONE_FROM', ''),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business Cloud API (Meta)
    |--------------------------------------------------------------------------
    | api_url   – Base URL, e.g. https://graph.facebook.com/v18.0
    | api_token – Permanent / temporary access token
    | phone_id  – Phone number ID from Meta Business dashboard
    */
    'whatsapp' => [
        'api_url'   => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v18.0'),
        'api_token' => env('WHATSAPP_API_TOKEN', ''),
        'phone_id'  => env('WHATSAPP_PHONE_ID', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | PayEdge Payroll Handoff API
    |--------------------------------------------------------------------------
    | endpoint  – Base URL of the PayEdge REST API (no trailing slash)
    | api_key   – Bearer token for Authorization header
    | timeout   – HTTP timeout in seconds
    */
    'payedge' => [
        'endpoint' => env('PAYEDGE_API_ENDPOINT', ''),
        'api_key'  => env('PAYEDGE_API_KEY', ''),
        'timeout'  => (int) env('PAYEDGE_API_TIMEOUT', 30),
    ],

    'bata' => [
        'endpoint'      => env('BATA_API_ENDPOINT', ''),
        'api_key'       => env('BATA_API_KEY', ''),
        'timeout'       => (int) env('BATA_API_TIMEOUT', 30),
        'source_system' => env('BATA_SOURCE_SYSTEM', 'bata'),
    ],

];
