<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
$app=Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php',commands: __DIR__.'/../routes/console.php',health: '/up')
    ->withMiddleware(function(Middleware $middleware):void{
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function(Exceptions $exceptions):void{})
    ->create();

// Packaged release: /AIDrama/releases/vX.Y.Z/bootstrap/app.php
// Private shared/.env and shared/storage are never within public/.
$shared=dirname(__DIR__,3).'/shared';
if(is_file($shared.'/.env') && is_dir($shared.'/storage')) {
    $app->useEnvironmentPath($shared);
    $app->useStoragePath($shared.'/storage');
}
return $app;
