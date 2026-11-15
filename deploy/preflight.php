<?php
/** AIDrama read-only preflight (PHP CLI). */
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
$errors=0;
$check=function(string $label,bool $ok,bool $required=true) use (&$errors): void {
    echo ($ok?'[ OK ] ':($required?'[FAIL] ':'[WARN] ')).$label.PHP_EOL;
    if (!$ok && $required) $errors++;
};
echo "=== AIDrama 环境检查（只读） ===\n";
echo "PHP ".PHP_VERSION.' / '.php_uname('m')." / ".PHP_OS_FAMILY."\n";
echo "Loaded PHP ini: ".(php_ini_loaded_file()?:'none')."\n";
echo "Scanned ini: ".(php_ini_scanned_files()?:'none')."\n";
$check('PHP >= 8.4',PHP_VERSION_ID>=80400);
foreach (['ctype','curl','dom','fileinfo','filter','hash','mbstring','openssl','pcre','pdo','pdo_mysql','session','tokenizer','xml'] as $ext) {
    $check("PHP extension: {$ext}",extension_loaded($ext));
}
$check('ZipArchive',class_exists('ZipArchive'));
$check('GD (recommended)',extension_loaded('gd'),false);
$check('OPcache (recommended)',extension_loaded('Zend OPcache'),false);
$check('Exif (optional)',extension_loaded('exif'),false);
$check('symlink (not required)',function_exists('symlink'),false);
$check('exec (not required)',function_exists('exec'),false);
$free=@disk_free_space(dirname(__DIR__));
$check('Free disk >= 512MB',is_numeric($free)&&$free>=512*1024*1024,false);
echo "\nRequired check failures: {$errors}\n";
exit($errors?1:0);
