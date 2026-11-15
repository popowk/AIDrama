<?php
/**
 * AIDrama public front controller. Never put private state inside public/.
 * The installation wizard lives at ../bootstrap/installer.php and becomes
 * inaccessible after shared/install.lock exists.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
$installed=is_file($root.'/shared/install.lock');
if (!$installed) {
    require $root.'/bootstrap/installer.php';
    exit;
}
$release=realpath($root.'/current');
$releaseParent=realpath($root.'/releases');
if (!$release || !$releaseParent ||
    !str_starts_with($release,$releaseParent.DIRECTORY_SEPARATOR) ||
    !preg_match('~^v\d+\.\d+\.\d+$~D',basename($release)) ||
    !is_file($release.'/vendor/autoload.php') ||
    !is_file($release.'/public/index.php')
) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'AIDrama is temporarily unavailable.';
    exit;
}
require $release.'/public/index.php';
