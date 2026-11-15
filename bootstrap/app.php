<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
// An old config.php produced by earlier AIDrama versions may omit the
// framework providers entirely. When Laravel reads a cached configuration,
// RegisterProviders deliberately skips merging defaults, which causes
// "Target class [view] does not exist" on /login.
// Remove only that incompatible generated cache. Never modify shared/.env,
// the database, install lock, API keys, or project files.
$cachedConfig=dirname(__DIR__).'/bootstrap/cache/config.php';
if(is_file($cachedConfig)) {
    $cached=@require $cachedConfig;
    $providers=is_array($cached) ? ($cached['app']['providers']??[]) : [];
    if(!is_array($providers)||
        !in_array(\\Illuminate\\View\\ViewServiceProvider::class,$providers,true)) {
        if(!@unlink($cachedConfig))
            throw new RuntimeException('旧版 Laravel 配置缓存缺失 view 服务提供者，请删除当前版本 bootstrap/cache/config.php 后重启 PHP-FPM');
        if(function_exists('opcache_invalidate'))@opcache_invalidate($cachedConfig,true);
    }
}
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
