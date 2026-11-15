<?php
/**
 * AIDrama read-only CLI diagnostics.
 * Usage: /www/server/php/84/bin/php <site-root>/deploy/diagnose.php
 * Never prints credentials, API keys, environment file contents or user data.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli') {http_response_code(403);exit;}

$root=realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$line=static function(string $name,mixed $value):void {
    echo str_pad($name,27).' : '.(is_bool($value)?($value?'YES':'NO'):(string)$value).PHP_EOL;
};
$yesNo=static fn(string $p):string=>is_file($p)?'exists':'missing';
$line('Site directory',$root);
$line('CLI PHP',PHP_VERSION.' / '.PHP_BINARY);
$line('PHP open_basedir',ini_get('open_basedir')?:'(disabled in CLI)');
$line('PHP disable_functions',ini_get('disable_functions')?:'(none)');
$line('Install lock',$yesNo($root.'/shared/install.lock'));
$line('Private .env',$yesNo($root.'/shared/.env'));
$line('Stable public/index.php',$yesNo($root.'/public/index.php'));
$line('Shared storage writable',is_writable($root.'/shared/storage'));
$line('Shared sessions writable',is_writable($root.'/shared/storage/framework/sessions'));
$line('Shared view cache writable',is_writable($root.'/shared/storage/framework/views'));
$line('Public path writable',is_writable($root.'/public'));
$line('Private Laravel log',$yesNo($root.'/shared/storage/logs/laravel.log'));
$line('Private bootstrap error log',$yesNo($root.'/shared/storage/logs/bootstrap-error.log'));
foreach(['pdo_mysql','mbstring','openssl','fileinfo','curl','zip'] as $name)
    $line('PHP extension '.$name,extension_loaded($name));
$markerFile=$root.'/shared/active-version';
$marker=is_file($markerFile)?trim((string)@file_get_contents($markerFile)):'';
$line('Version marker',$marker?:'(missing)');
$releases=[];
$parent=realpath($root.'/releases');
foreach(glob($root.'/releases/v*',GLOB_ONLYDIR)?:[] as $path) {
    $tag=basename($path);
    if(!preg_match('~^v\d+\.\d+\.\d+$~D',$tag) || !$parent || is_link($path))continue;
    $dir=realpath($path);
    if(!$dir || dirname($dir)!==$parent)continue;
    $complete=trim((string)@file_get_contents($dir.'/VERSION'))===$tag;
    foreach(['vendor/autoload.php','bootstrap/app.php','public/index.php'] as $file)
        if(!is_file($dir.'/'.$file))$complete=false;
    $releases[$tag]=$complete;
}
uksort($releases,static fn($a,$b)=>version_compare($b,$a));
foreach($releases as $tag=>$complete)$line('Release '.$tag,$complete?'complete':'INCOMPLETE');
$chosen=(!empty($releases[$marker]) ? $marker : array_key_first(array_filter($releases)));
$line('Boot candidate',$chosen?:'(none)');

$paths=[$root.'/public/index.php'];
if($chosen) {
    $release=$root.'/releases/'.$chosen;
    $paths[]=$release.'/public/index.php';
    $paths[]=$release.'/bootstrap/app.php';
    $paths[]=$release.'/routes/web.php';
}
foreach($paths as $path) {
    if(!is_file($path))continue;
    try {
        token_get_all((string)file_get_contents($path),TOKEN_PARSE);
        $line('PHP syntax '.basename(dirname($path)).'/'.basename($path),'OK');
    }catch(ParseError $e) {
        $line('PHP syntax '.basename($path),'ERROR at line '.$e->getLine());
    }
}
if(!$chosen) {
    echo "No complete release found. Re-extract AIDrama.zip without removing shared/.\n";
    exit(1);
}
try {
    $release=$root.'/releases/'.$chosen;
    require_once $release.'/vendor/autoload.php';
    $app=require $release.'/bootstrap/app.php';
    $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $line('Laravel bootstrap','OK');
    $viewBound=$app->bound('view');
    $line('Laravel view service',$viewBound?'OK':'MISSING');
    $cacheFile=$release.'/bootstrap/cache/config.php';
    $line('Configuration cached',is_file($cacheFile));
    if(is_file($cacheFile)) {
        $providers=\Illuminate\Support\Facades\Config::get('app.providers',[]);
        $line('Core View provider',is_array($providers)&&
            in_array(\Illuminate\View\ViewServiceProvider::class,$providers,true)?'OK':'MISSING');
    }
    \Illuminate\Support\Facades\DB::connection()->select('SELECT 1');
    $line('MySQL connection','OK');
    $schema=\Illuminate\Support\Facades\Schema::getFacadeRoot();
    $line('Projects table',$schema->hasTable('projects')?'exists':'MISSING');
    $line('Generation jobs table',$schema->hasTable('generation_jobs')?'exists':'MISSING');
    if($schema->hasTable('migrations')) {
        $ran=\Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all();
        $files=glob($release.'/database/migrations/*.php')?:[];
        $pending=0;
        foreach($files as $path)if(!in_array(pathinfo($path,PATHINFO_FILENAME),$ran,true))$pending++;
        $line('Pending SQL migrations',$pending);
    } else $line('Migration table','MISSING');
} catch(\Throwable $e) {
    $line('Laravel / DB error type',get_class($e));
    $line('Error source',basename($e->getFile()).':'.$e->getLine());
    echo "Read the private Laravel/PHP error logs for the original error detail.\n";
    exit(2);
}
echo "Done. CLI PHP may use different settings from BaoTa PHP-FPM.\n";
