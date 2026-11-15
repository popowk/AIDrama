<?php
return [
    'default'=>env('LOG_CHANNEL','single'),
    'channels'=>[
        'single'=>['driver'=>'single','path'=>storage_path('logs/laravel.log'),'level'=>env('LOG_LEVEL','warning')],
        'stderr'=>['driver'=>'monolog','level'=>env('LOG_LEVEL','warning'),'handler'=>Monolog\Handler\StreamHandler::class,'with'=>['stream'=>'php://stderr']],
        'stack'=>['driver'=>'stack','channels'=>['single'],'ignore_exceptions'=>false],
    ],
];
