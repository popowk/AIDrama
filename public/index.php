<?php
/** AIDrama public dispatcher. Private version marker lives outside public/. */
declare(strict_types=1);
$root=dirname(__DIR__);
if(!is_file($root.'/shared/install.lock')) {
    require $root.'/bootstrap/installer.php';
    exit;
}
$marker=$root.'/shared/active-version';
$tag=is_file($marker)?trim((string)file_get_contents($marker)):'';
if(!preg_match('~^v\d+\.\d+\.\d+$~D',$tag)) {
    http_response_code(503); echo 'AIDrama version configuration is unavailable.'; exit;
}
$releaseReal=realpath($root.'/releases/'.$tag);
$releaseParent=realpath($root.'/releases');
if(!$releaseReal || !$releaseParent || dirname($releaseReal)!==$releaseParent ||
   !is_file($releaseReal.'/vendor/autoload.php') ||
   !is_file($releaseReal.'/public/index.php')) {
    http_response_code(503); echo 'AIDrama is temporarily unavailable.'; exit;
}
require $releaseReal.'/public/index.php';
