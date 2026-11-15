<?php
/**
 * AIDrama stable document-root controller.
 *
 * The version marker is authoritative when its target is valid. If a user
 * deleted the referenced release, recover from the newest *complete* release
 * instead of showing a generic 503. No symlinks or hard-coded site paths.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
if(!is_file($root.'/shared/install.lock')) {
    $installer=$root.'/bootstrap/installer.php';
    if(!is_file($installer)) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('AIDrama installer is missing. Check ZIP extraction and PHP open_basedir.');
    }
    require $installer;
    exit;
}

$releaseParent=realpath($root.'/releases');
$releaseIsValid=static function(string $tag) use ($root,$releaseParent): ?string {
    if(!$releaseParent || !preg_match('~^v\d+\.\d+\.\d+$~D',$tag))return null;
    $path=$root.'/releases/'.$tag;
    if(is_link($path))return null;
    $dir=realpath($path);
    if(!$dir || dirname($dir)!==$releaseParent || basename($dir)!==$tag)return null;
    foreach(['VERSION','vendor/autoload.php','bootstrap/app.php','public/index.php'] as $file)
        if(!is_file($dir.'/'.$file))return null;
    if(trim((string)@file_get_contents($dir.'/VERSION'))!==$tag)return null;
    return $dir;
};

$marker=$root.'/shared/active-version';
$tag=is_file($marker)?trim((string)@file_get_contents($marker)):'';
$release=$releaseIsValid($tag);
if(!$release) {
    // Only recover if the configured version is truly unavailable. Merely
    // uploading a newer release must NOT skip database migration or approval.
    $candidates=[];
    foreach(glob($root.'/releases/v*',GLOB_ONLYDIR)?:[] as $path) {
        $candidate=basename($path);
        if($releaseIsValid($candidate))$candidates[]=$candidate;
    }
    usort($candidates,static fn(string $a,string $b):int => version_compare($b,$a));
    if($candidates) {
        $tag=$candidates[0];
        $release=$releaseIsValid($tag);
        // Best-effort repair. If shared/ isn't writable, keep serving from
        // the recovered release and retry the marker repair next request.
        $next=$root.'/shared/.active-version-recover-'.bin2hex(random_bytes(4));
        if(@file_put_contents($next,$tag."\n",LOCK_EX)!==false) {
            @chmod($next,0600);
            if(!@rename($next,$marker))@unlink($next);
        }
        // Also restore the public stylesheet when recovering a deleted
        // version whose CSS may no longer match the replacement application.
        $stylesheet=$release.'/public/app.css';
        if(is_file($stylesheet)) {
            $cssNext=$root.'/shared/.css-recovery-'.bin2hex(random_bytes(4));
            if(@copy($stylesheet,$cssNext)) {
                @chmod($cssNext,0644);
                if(!@rename($cssNext,$root.'/public/app.css'))@unlink($cssNext);
            }
        }
    }
}
if(!$release) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('AIDrama has no complete application version under releases/. Extract the official AIDrama.zip without removing shared/.');
}
// A hosting-side PHP fatal can otherwise render as a completely blank page
// when display_errors is off. Keep full diagnostics outside the web root.
$recordError=static function(string $type,string $detail) use ($root):string {
    $id=bin2hex(random_bytes(5));
    $row=date(DATE_ATOM).' incident='.$id.' type='.$type.' '.$detail."\n";
    $path=$root.'/shared/storage/logs/bootstrap-error.log';
    if(is_dir(dirname($path))) {
        @file_put_contents($path,$row,FILE_APPEND|LOCK_EX);
        @chmod($path,0600);
    }
    error_log('[AIDrama] startup incident '.$id.' (see private shared/storage/logs/bootstrap-error.log)');
    return $id;
};
$renderError=static function(string $id):void {
    if(!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8">'.
        '<title>AIDrama 启动错误</title><body style="font:16px system-ui,sans-serif;'.
        'max-width:700px;margin:12vh auto;padding:20px;line-height:1.8">'.
        '<h1>AIDrama 启动失败（HTTP 500）</h1>'.
        '<p>PHP 启动程序时遇到错误，请不要删除数据库或 shared 目录。</p>'.
        '<p>错误编号：<code>'.htmlspecialchars($id,ENT_QUOTES,'UTF-8').'</code></p>'.
        '<p>在宝塔终端查看私有日志：<code>shared/storage/logs/bootstrap-error.log</code>，'.
        '也可检查 PHP 8.4 / OpenResty 错误日志。</p></body></html>';
};
$completed=false;
register_shutdown_function(static function() use (&$completed,$recordError,$renderError):void {
    if($completed)return;
    $e=error_get_last();
    if(!$e || !in_array($e['type']??0,[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))
        return;
    $id=$recordError('fatal',
        (string)($e['message']??'').'; file='.(string)($e['file']??'').'; line='.(int)($e['line']??0));
    $renderError($id);
});
try {
    require $release.'/public/index.php';
    $completed=true;
} catch(\Throwable $e) {
    $completed=true;
    $id=$recordError(get_class($e),
        $e->getMessage().'; file='.$e->getFile().'; line='.$e->getLine());
    $renderError($id);
}

