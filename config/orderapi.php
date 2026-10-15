<?php

declare(strict_types=1);

/*
 * Raw environment values of the order API. They are parsed and validated by App\Config\Settings, which fails
 * fast on an unsafe production configuration. env() only appears in config files so `config:cache` works.
 */
return [
    'APP_ENV' => env('APP_ENV', 'development'),
    'JWT_PUBLIC_KEY_PATH' => env('JWT_PUBLIC_KEY_PATH'),
    'JWT_AUDIENCE' => env('JWT_AUDIENCE'),
    'JWT_ISSUER' => env('JWT_ISSUER'),
    'CORS_ORIGINS' => env('CORS_ORIGINS'),
    'MAX_BODY_BYTES' => env('MAX_BODY_BYTES'),
    'RATE_LIMIT_PER_USER' => env('RATE_LIMIT_PER_USER'),
    'RATE_LIMIT_WRITES' => env('RATE_LIMIT_WRITES'),
    'RATE_LIMIT_PER_IP' => env('RATE_LIMIT_PER_IP'),
    'BROKER_URL' => env('BROKER_URL'),
    'SMTP_HOST' => env('SMTP_HOST'),
    'SMTP_FROM' => env('SMTP_FROM'),
];
