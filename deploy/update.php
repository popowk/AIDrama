<?php
/** AIDrama GitHub Releases updater. PHP 8.4 CLI, no Node or Composer on server. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
const REPO='popowk/AIDrama';
const ZIP_NAME='AIDrama.zip';
const SHA_NAME='AIDrama.zip.sha256';
function msg(string $s):void{fwrite(STDOUT,$s."\n");}
function stop(string $s):never{throw new RuntimeException($s);}
function mkdirSafe(string $p):void{if(!is_dir($p)&&!mkdir($p,0750,true)&&!is_dir($p))stop("创建目录失败: {$p}");}
function api(string $url):array{
 $c=curl_init($url);
 curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>30,
 CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
 CURLOPT_USERAGENT=>'AIDrama-updater',CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json']]);
 $body=curl_exec($c);$http=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$err=curl_error($c);curl_close($c);
 if(!is_string($body)||$http!==200)stop("GitHub Release 请求失败 HTTP {$http}: {$err}");
 $json=json_decode($body,true);if(!is_array($json))stop('GitHub 返回的 JSON 无效');return $json;
}
function fetchAsset(string $url,string $target):void{
 if(!str_starts_with($url,'https://github.com/'.REPO.'/releases/download/'))stop('发布文件 URL 来源不符');
 $fp=fopen($target,'wb');if(!$fp)stop('临时文件创建失败');
 $c=curl_init($url);
 curl_setopt_array($c,[CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,
 CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>300,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
 CURLOPT_USERAGENT=>'AIDrama-updater']);
 if(defined('CURLOPT_REDIR_PROTOCOLS_STR'))curl_setopt($c,CURLOPT_REDIR_PROTOCOLS_STR,'https');
 else curl_setopt($c,CURLOPT_REDIR_PROTOCOLS,CURLPROTO_HTTPS);
 $ok=curl_exec($c);$http=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$err=curl_error($c);
 curl_close($c);fclose($fp);
 if(!$ok||$http!==200){@unlink($target);stop("文件下载失败 HTTP {$http}: {$err}");}
}
function archiveCheck(string $file):void{
 $z=new ZipArchive();if($z->open($file)!==true)stop('ZIP 无法打开');
 if($z->numFiles>30000)stop('ZIP 文件数量过多');
 $size=0;
 for($i=0;$i<$z->numFiles;$i++){
  $it=$z->statIndex($i);$p=(string)($it['name']??'');
  if(!$p||str_starts_with($p,'/')||str_contains($p,"\\")||preg_match('~(^|/)\.\.?(/|$)~',$p)||str_contains($p,"\0"))stop("危险路径: {$p}");
  if($p==='.env'||str_starts_with($p,'storage/')||str_starts_with($p,'shared/')||
    str_starts_with($p,'public/storage/')||str_starts_with($p,'public/.')||
    preg_match('~^public/.*\.(?:ini|log|sql|sqlite|db|bak|zip|env)$~i',$p))
    stop('发布包包含不允许公开的内容');
  $size+=(int)($it['size']??0);if($size>2147483648)stop('解压内容超过安全限制');
  if($z->getExternalAttributesIndex($i,$os,$mode)&&$os===ZipArchive::OPSYS_UNIX&&(($mode>>16)&0170000)===0120000)stop('ZIP 禁止软链接');
 }
 $z->close();
}
function fingerprints(string $d):array{
 $a=[];foreach(glob($d.'/database/migrations/*.php')?:[] as $p)$a[basename($p)]=hash_file('sha256',$p);
 ksort($a);return $a;
}
function activeTag(string $root):string{
 $p=$root.'/shared/active-version';
 $value=is_file($p)?trim((string)file_get_contents($p)):'';
 if(!preg_match('~^v\d+\.\d+\.\d+$~D',$value))stop('当前活动版本标记缺失或无效');
 return $value;
}
function switchVersion(string $root,string $tag):void{
 if(!preg_match('~^v\d+\.\d+\.\d+$~D',$tag)||!is_dir($root.'/releases/'.$tag))stop('目标版本无效');
 $p=$root.'/shared/active-version.staging-'.bin2hex(random_bytes(4));
 if(file_put_contents($p,$tag."\n",LOCK_EX)===false)stop('写入私有版本指针失败');
 chmod($p,0600);
 if(!rename($p,$root.'/shared/active-version'))stop('版本切换失败');
}
try{
 $mode=$argv[1]??'check';if(!in_array($mode,['check','apply','rollback'],true))stop('用法: php update.php check|apply|rollback');
 if(!extension_loaded('curl')||!class_exists('ZipArchive'))stop('缺少 curl 或 zip');
 $parent=realpath(__DIR__.'/..')?:dirname(__DIR__);
 $root=preg_match('~^(.*)/releases/v\d+\.\d+\.\d+$~',$parent,$m)?$m[1]:$parent;
 if(!str_starts_with($root,'/')||$root==='/')stop('部署目录异常');
 mkdirSafe($root.'/releases');mkdirSafe($root.'/tmp');
 $lock=fopen($root.'/update.lock','c');
 if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))stop('已有另一个更新任务');
 if(!is_file($root.'/shared/install.lock'))stop('请先用完整 AIDrama.zip 的网页安装向导完成初始化');
 if(!is_file($root.'/shared/.env'))stop('私有 .env 缺失，拒绝继续升级');
 if($mode==='rollback'){
  $last=trim((string)@file_get_contents($root.'/previous-release'));
  if(!preg_match('~^v\d+\.\d+\.\d+$~',$last)||!is_dir($root.'/releases/'.$last))stop('没有上个版本');
  switchVersion($root,$last);
  $css=$root.'/releases/'.$last.'/public/app.css';
  if(is_file($css)){ $tmpCss=$root.'/public/app-rollback-'.bin2hex(random_bytes(4));copy($css,$tmpCss);rename($tmpCss,$root.'/public/app.css'); }
  msg("回滚代码至 {$last}；数据库不回滚");exit;
 }
 $json=api('https://api.github.com/repos/'.REPO.'/releases/latest');
 $tag=(string)($json['tag_name']??'');if(!preg_match('~^v\d+\.\d+\.\d+$~',$tag))stop('版本标签必须为 vX.Y.Z');
 $now=activeTag($root);
 msg('当前：'.($now?:'未安装').'，最新：'.$tag);
 if($now===$tag){msg('无需更新');exit;}
 if($now&&version_compare(substr($tag,1),substr($now,1),'<='))
  stop('最新版本不高于当前版本，拒绝降级');
 $assets=[];foreach(($json['assets']??[]) as $a)$assets[$a['name']??'']=$a['browser_download_url']??'';
 if(empty($assets[ZIP_NAME])||empty($assets[SHA_NAME]))stop('Release 缺少 ZIP 和 SHA256 文件');
 if($mode==='check'){msg('发现可用新版本');exit;}
 $zipFile=$root.'/tmp/'.$tag.'.zip';$shaFile=$root.'/tmp/'.$tag.'.sha256';
 fetchAsset($assets[ZIP_NAME],$zipFile);fetchAsset($assets[SHA_NAME],$shaFile);
 $line=trim((string)file_get_contents($shaFile));
 if(!preg_match('/^([a-fA-F0-9]{64})\s+\*?AIDrama\.zip$/D',$line,$found))stop('SHA256 文件格式不对');
 if(!hash_equals(strtolower($found[1]),hash_file('sha256',$zipFile)))stop('SHA256 校验失败');
 archiveCheck($zipFile);$dir=$root.'/releases/'.$tag;
 if(!is_dir($dir)){
  $tmp=$root.'/tmp/stage-'.bin2hex(random_bytes(6));mkdirSafe($tmp);
  $z=new ZipArchive();if($z->open($zipFile)!==true||!$z->extractTo($tmp))stop('解压失败');$z->close();
  $stageRelease=$tmp.'/releases/'.$tag;
  foreach(['VERSION','artisan','bootstrap/app.php','public/index.php','vendor/autoload.php','.env.example','public/app.css'] as $f){
   if(!is_file($stageRelease.'/'.$f))stop('发行包缺少 '.$f);
  }
  if(trim((string)file_get_contents($stageRelease.'/VERSION'))!==$tag)stop('ZIP 版本与 Release 不一致');
  if(!is_file($tmp.'/public/index.php')||!is_file($tmp.'/bootstrap/installer.php'))
   stop('缺少一体化安装结构');
  foreach(['app/public','app/private','framework/cache/data','framework/sessions','framework/views','logs'] as $sub)
   mkdirSafe($root.'/shared/storage/'.$sub);
  // Private configuration and storage are resolved by the staged bootstrap.
  mkdirSafe($stageRelease.'/bootstrap/cache');
  if(!rename($stageRelease,$dir))stop('版本保存失败');
  msg('新版本预备目录: '.$dir);
 }
 $env=(string)file_get_contents($root.'/shared/.env');
 if(!preg_match('/^APP_KEY=base64:[A-Za-z0-9+\/=]+$/m',$env))stop('APP_KEY 无效，拒绝升级');
 // Bootstrap in-process rather than invoking disabled shell functions.
 try {
    require_once $dir.'/vendor/autoload.php';
    $app=require $dir.'/bootstrap/app.php';
    $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    if(!is_string($app->version()))stop('Laravel 自检失败');
 } catch(\Throwable $e) {stop('Laravel 启动自检失败：'.get_class($e));}
 if($now&&fingerprints($root.'/releases/'.$now)!==fingerprints($dir)){
  if(!in_array('--allow-migrations',$argv,true))
   stop('数据库迁移文件已变化，自动更新暂停；备份数据库并审核迁移后，手动 apply --allow-migrations');
  msg('管理员允许迁移版本切换。本脚本不会自动执行数据库迁移。');
 }
 if($now)file_put_contents($root.'/previous-release',$now,LOCK_EX);
 switchVersion($root,$tag);
 // Only the stylesheet is a public static asset. Keep public/index.php untouched:
 // it permanently routes to a locked installed version or first-run wizard.
 $css=$dir.'/public/app.css';
 $cssNext=$root.'/public/app-css-'.bin2hex(random_bytes(4));
 if(!copy($css,$cssNext)||!rename($cssNext,$root.'/public/app.css'))
   msg('代码更新成功，但 CSS 同步失败，请检查 public 目录权限');
 // Refresh the stable CLI updater from the new release after a successful switch.
 $updater=$dir.'/deploy/update.php';
 if(is_file($updater) && is_dir($root.'/deploy')) {
    $nextUpdater=$root.'/deploy/update.staged-'.bin2hex(random_bytes(4)).'.php';
    if(copy($updater,$nextUpdater)) {
       chmod($nextUpdater,0640);
       if(!rename($nextUpdater,$root.'/deploy/update.php'))@unlink($nextUpdater);
    }
 }
 @unlink($zipFile);@unlink($shaFile);
 msg('代码更新成功：'.$tag.'，宝塔站点根目录 '.$root.'/public');
}catch(Throwable $e){fwrite(STDERR,'[ERROR] '.$e->getMessage()."\n");exit(1);}
