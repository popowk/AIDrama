<?php
return [
    'name' => env('APP_NAME', 'AIDrama'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://127.0.0.1'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Shanghai'),
    'locale' => 'zh_CN',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
    'previous_keys' => array_filter(explode(',', (string) env('APP_PREVIOUS_KEYS', ''))),
    // A cached configuration bypasses Laravel's automatic merge of default
    // service providers. Include them explicitly so Blade/view, session,
    // validation and database bindings exist with config:cache on PHP-FPM.
    'providers' => \Illuminate\Support\ServiceProvider::defaultProviders()
        ->merge([\App\Providers\AppServiceProvider::class])->toArray(),
];
