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
require $release.'/public/index.php';
