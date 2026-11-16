<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Deployed layout: <site>/releases/vX.Y.Z/bootstrap/app.php.
// Release directories can be read-only or owned by a deployment account,
// so Laravel must not write manifests or optimized files into them.
// Each release uses separate private bootstrap cache files in shared/storage.
$release=dirname(__DIR__);
$shared=dirname($release,2).'/shared';
$installed=is_file($shared.'/.env') && is_dir($shared.'/storage');

if($installed) {
    $cacheDir=$shared.'/storage/framework/cache/bootstrap/'.basename($release);
    if(!is_dir($cacheDir) && !@mkdir($cacheDir,0750,true) && !is_dir($cacheDir))
        throw new \RuntimeException('无法创建 Laravel 私有启动缓存目录，请检查 shared/storage 的 www 写入权限');
    if(!is_writable($cacheDir))
        throw new \RuntimeException('Laravel 私有启动缓存目录不可写，请检查 shared/storage 的 www 写入权限');

    // Recover automatically if a previously generated config cache has an
    // incomplete provider registry (the cause of earlier blank login pages).
    $configCache=$cacheDir.'/config.php';
    if(is_file($configCache)) {
        $config=@include $configCache;
        $providers=is_array($config)?($config['app']['providers']??[]):[];
        if(!is_array($providers) ||
            !in_array(\Illuminate\View\ViewServiceProvider::class,$providers,true)) {
            if(!@unlink($configCache))
                throw new \RuntimeException('Laravel 配置缓存已损坏且不可清理，请检查 shared/storage 写入权限');
            if(function_exists('opcache_invalidate'))@opcache_invalidate($configCache,true);
        }
    }

    // Laravel 13 resolves these via Illuminate\Support\Env. Set both default
    // environment adapters before Application::configure registers services.
    // Never store cache files under public/ or inside the versioned release.
    foreach([
        'APP_PACKAGES_CACHE'=>'packages.php',
        'APP_SERVICES_CACHE'=>'services.php',
        'APP_CONFIG_CACHE'=>'config.php',
        'APP_ROUTES_CACHE'=>'routes-v7.php',
        'APP_EVENTS_CACHE'=>'events.php',
    ] as $key=>$filename) {
        $_SERVER[$key]=$cacheDir.'/'.$filename;
        $_ENV[$key]=$cacheDir.'/'.$filename;
    }
}

$app=Application::configure(basePath: $release)
    ->withRouting(web: __DIR__.'/../routes/web.php',commands: __DIR__.'/../routes/console.php',health: '/up')
    ->withMiddleware(function(Middleware $middleware):void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function(Exceptions $exceptions):void {})
    ->create();

if($installed) {
    $app->useEnvironmentPath($shared);
    $app->useStoragePath($shared.'/storage');
}
return $app;
