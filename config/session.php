<?php
return [
    'driver' => env('SESSION_DRIVER','file'),
    'lifetime' => (int)env('SESSION_LIFETIME',120),
    'expire_on_close' => false,
    'encrypt' => false,
    'files' => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION'),
    'table'=>'sessions', 'store'=>env('SESSION_STORE'),
    'lottery'=>[2,100],
    'cookie'=>strtolower(str_replace(' ', '_', env('APP_NAME','aidrama'))).'_session',
    'path'=>'/', 'domain'=>env('SESSION_DOMAIN'),
    'secure'=>(bool)env('SESSION_SECURE_COOKIE',false),
    'http_only'=>true, 'same_site'=>'lax',
];
