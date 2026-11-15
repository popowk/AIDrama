<?php
/** Standalone first-run installer bundled with AIDrama release. Not reachable as a public URL. */
declare(strict_types=1);
if (PHP_SAPI === 'cli') { fwrite(STDERR, "Browser installer: open your website instead.\n"); exit(1); }
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'");
ini_set('display_errors','0');
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Strict');
session_name('AIDRAMA_INSTALL');
session_start();

$root=dirname(__DIR__);
$errors = [];
$success = false;
if (!is_file($root.'/public/index.php') || is_link($root.'/public')) {
    http_response_code(404); exit('Not found');
}
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fail(string $message): never { throw new RuntimeException($message); }
function makeDir(string $path): void {
    if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) fail('无法创建目录：'.basename($path).'。请在宝塔将 AIDrama 目录所有者设为 www。');
}
function checks(string $root): array {
    $list=[
        ['PHP 8.4+',PHP_VERSION_ID>=80400],
        ['PDO MySQL',extension_loaded('pdo_mysql')],
        ['fileinfo',extension_loaded('fileinfo')],
        ['cURL',extension_loaded('curl')],
        ['ZipArchive',class_exists('ZipArchive')],
        ['OpenSSL',extension_loaded('openssl')],
        ['mbstring',extension_loaded('mbstring')],
        ['DOM / XML',extension_loaded('dom')&&extension_loaded('xml')],
        ['Ctype / Tokenizer',extension_loaded('ctype')&&extension_loaded('tokenizer')],
        ['Session',extension_loaded('session')],
        ['网站根目录指向 AIDrama/public',realpath((string)($_SERVER['DOCUMENT_ROOT']??''))===realpath($root.'/public')],
        ['站点文件目录可写',is_writable($root)],
        ['public 目录可写',is_writable($root.'/public')],
    ];
    return $list;
}
function envQuote(string $v): string {
    if(preg_match('/[\x00-\x1F]/',$v))fail('配置值中包含无效控制字符');
    return '"'.str_replace(['\\','"','
}
function install(string $root,array $input): string {
    $appUrl=rtrim(trim((string)($input['app_url']??'')),'/');
    $url=parse_url($appUrl);
    $host=strtolower((string)($url['host']??''));
    if(!$url||!in_array($url['scheme']??'', ['https','http'],true)||!$host||
        isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||
        !in_array(($url['path']??''),['','/'],true) ||
        (($url['scheme']??'')!=='https'&&!in_array($host,['localhost','127.0.0.1'],true)) ||
        strlen($appUrl)>220)fail('网站地址需为 HTTPS 域名（本机 localhost 可用 HTTP），不得包含路径或账号');
    $dbHost=trim((string)($input['db_host']??''));
    $dbName=trim((string)($input['db_name']??''));
    $dbUser=trim((string)($input['db_user']??''));
    $dbPassword=(string)($input['db_password']??'');
    $port=(int)($input['db_port']??3306);
    $admin=(string)($input['admin_password']??'');
    if(!preg_match('/^[a-zA-Z0-9.-]{1,150}$/D',$dbHost) ||
      !preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$dbName) ||
      !preg_match('/^[a-zA-Z0-9_@.-]{1,100}$/D',$dbUser) ||
      $port<1||$port>65535)fail('数据库地址、端口、库名或用户名格式不正确');
    if(strlen($admin)<12||strlen($admin)>256||!hash_equals($admin,(string)($input['admin_confirm']??'')))fail('管理员密码需至少12位且两次一致');
    $pdo=null;
    try{
        $pdo=new PDO("mysql:host={$dbHost};port={$port};dbname={$dbName};charset=utf8mb4",
            $dbUser,$dbPassword,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT=>8,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
            ]);
        $pdo->query('SELECT 1');
        $exists=$pdo->query("SHOW TABLES LIKE 'projects'")->fetchAll();
        if($exists)fail('数据库中已存在 AIDrama 项目表。为了保护数据，安装程序不会覆盖旧数据。');
    } catch(PDOException $e) {
        fail('MySQL 连接失败。请先在宝塔创建空数据库和专用用户，并检查用户名、密码及访问权限。');
    }
    unset($pdo,$dbPassword);
    // The full application is already included in the single ZIP. No internet access required.
    $found=array_values(array_filter(glob($root.'/releases/v*')?:[],
        static fn(string $p):bool => is_dir($p) && preg_match('~^v\d+\.\d+\.\d+$~',basename($p))));
    if(count($found)!==1)fail('安装包不完整：首次安装需且只能包含一个 releases/vX.Y.Z 目录');
    $versionDir=realpath($found[0]);
    if(!$versionDir || is_link($found[0]))fail('发行版本目录不合法');
    $tag=basename($versionDir);
    foreach(['artisan','vendor/autoload.php','bootstrap/app.php','public/index.php','VERSION'] as $required){
        if(!is_file($versionDir.'/'.$required))fail('安装包缺少 '.$required);
    }
    if(trim((string)file_get_contents($versionDir.'/VERSION'))!==$tag)fail('安装包版本不匹配');
    if(file_exists($root.'/shared/.env')||is_file($root.'/shared/install.lock')||
       file_exists($root.'/current')||is_link($root.'/current'))
        fail('检测到已经存在应用配置或版本指针，禁止重复安装');
    makeDir($root.'/shared');
    foreach(['app/private','framework/cache/data','framework/sessions','framework/views','logs'] as $sub){
        makeDir($root.'/shared/storage/'.$sub);
    }
    $key=base64_encode(random_bytes(32));
    $env="APP_NAME=AIDrama\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:{$key}\n".
        "APP_URL=".envQuote($appUrl)."\nAPP_TIMEZONE=Asia/Shanghai\n".
        "LOG_CHANNEL=single\nLOG_LEVEL=warning\n".
        "DB_CONNECTION=mysql\nDB_HOST=".envQuote($dbHost)."\nDB_PORT={$port}\n".
        "DB_DATABASE=".envQuote($dbName)."\nDB_USERNAME=".envQuote($dbUser)."\n".
        "DB_PASSWORD_B64=".base64_encode((string)$input['db_password'])."\n".
        "DB_CHARSET=utf8mb4\nDB_COLLATION=utf8mb4_unicode_ci\n".
        "SESSION_DRIVER=file\nSESSION_LIFETIME=120\n".
        "SESSION_SECURE_COOKIE=".(($url['scheme']??'')==='https'?'true':'false')."\n".
        "CACHE_STORE=file\nQUEUE_CONNECTION=database\n";
    $envFile=$root.'/shared/.env';
    if(file_put_contents($envFile,$env,LOCK_EX)===false)fail('无法保存 .env');
    chmod($envFile,0600);
    if(!symlink($envFile,$versionDir.'/.env') ||
       !symlink($root.'/shared/storage',$versionDir.'/storage'))fail('连接私有配置和存储目录失败');
    makeDir($versionDir.'/bootstrap/cache');
    // Run migrations without invoking shell, Composer, Node or Supervisor.
    require $versionDir.'/vendor/autoload.php';
    $app=require $versionDir.'/bootstrap/app.php';
    $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    if($kernel->call('migrate',['--force'=>true,'--no-interaction'=>true])!==0)fail('数据库迁移失败，请检查 MySQL 权限');
    $hash=password_hash($admin,PASSWORD_DEFAULT);
    $adminFile=$root.'/shared/storage/app/private/admin.hash';
    if(file_put_contents($adminFile,$hash,LOCK_EX)===false)fail('管理员密钥保存失败');
    chmod($adminFile,0600);
    // Atomically enable the already-unpacked application using a private release pointer.
    $next=$root.'/current.next';
    if(is_link($next)||file_exists($next))fail('检测到异常版本指针');
    if(!symlink($versionDir,$next) || !rename($next,$root.'/current'))fail('应用版本切换失败');
    file_put_contents($root.'/shared/install.lock',
        json_encode(['version'=>$tag,'installed_at'=>date(DATE_ATOM)]),LOCK_EX);
    chmod($root.'/shared/install.lock',0600);
    @unlink($root.'/shared/setup-token.txt');
    // The persistent public/index.php is a minimal locked dispatcher, not an installer.
    return $tag;
}
try {
    makeDir($root.'/shared');
    $tokenFile=$root.'/shared/setup-token.txt';
    if(is_file($root.'/shared/install.lock')){http_response_code(404);exit('Installation is locked');}
    if(!is_file($tokenFile)){
        if(file_put_contents($tokenFile,bin2hex(random_bytes(24)),LOCK_EX)===false) fail('无法创建安装口令，请检查目录权限');
        chmod($tokenFile,0600);
    }
    if(empty($_SESSION['aidrama_csrf'])) $_SESSION['aidrama_csrf']=bin2hex(random_bytes(24));
    $post=$_SERVER['REQUEST_METHOD']==='POST';
    if($post){
        $tls=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ||
             strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https';
        $loopback=in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true);
        if(!$tls && !$loopback)fail('请先在宝塔启用 HTTPS，并使用 https:// 地址打开安装页面');

        if(!hash_equals((string)$_SESSION['aidrama_csrf'],(string)($_POST['csrf']??'')))fail('页面已过期，请刷新后重新提交');
        $action=(string)($_POST['action']??'');
        if($action==='unlock'){
            $stored=trim((string)file_get_contents($tokenFile));
            if(!hash_equals($stored,trim((string)($_POST['setup_token']??''))))fail('安装口令不正确');
            session_regenerate_id(true);$_SESSION['aidrama_unlocked']=true;
            $_SESSION['aidrama_csrf']=bin2hex(random_bytes(24));
        }elseif($action==='install'){
            if(empty($_SESSION['aidrama_unlocked']))fail('请先验证安装口令');
            foreach(checks($root) as [$name,$ok])if(!$ok)fail('环境检测未通过：'.$name);
            $lock=fopen($root.'/shared/install-process.lock','c');
            if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))fail('另一个安装请求正在执行');
            set_time_limit(300);
            $tag=install($root,$_POST);
            $success=true;
            $_SESSION=[];
            session_destroy();
        }else fail('无效的操作');
    }
}catch(Throwable $e){
    $errors[]=$e->getMessage();
}
if($success){ header('Location: /login',true,303); exit; }
$unlocked=!empty($_SESSION['aidrama_unlocked']);
$checks=checks($root);
$ready=count(array_filter($checks,static fn($x)=>!$x[1]))===0;
$defaultUrl='https://'.preg_replace('/[^a-zA-Z0-9.\-:]/','',(string)($_SERVER['HTTP_HOST']??'example.com'));
?>
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AIDrama · 首次安装</title><style>
:root{color-scheme:dark;--bg:#0b1220;--panel:#151e31;--line:#2d3c57;--text:#ecf2ff;--muted:#a5b0c6;--blue:#92a8ff}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 10% 0,#233458 0,#0b1220 45%);font-family:system-ui,-apple-system,"Microsoft YaHei",sans-serif;color:var(--text);line-height:1.6}
.wrap{max-width:840px;margin:45px auto 65px;padding:0 18px}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}.brand{font-size:25px;font-weight:750}.brand span{color:#9eb5ff}small,.muted{color:var(--muted)}h1{margin:6px 0;font-size:27px}h2{font-size:18px;margin:0 0 15px}.panel{padding:27px;border:1px solid var(--line);border-radius:18px;background:var(--panel);box-shadow:0 15px 35px #0003;margin-bottom:18px}
.pills{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 28px}.pill{background:#223252;padding:7px 12px;border-radius:30px;font-size:12px;color:#b5c5ed}
.checks{display:grid;grid-template-columns:1fr 1fr;gap:9px}.check{background:#1c2940;border:1px solid #34435f;padding:9px 12px;border-radius:9px;font-size:13px}.ok{color:#66d5b3}.bad{color:#ff9aa6}.notice{background:#5a2933;padding:10px 14px;border-radius:10px;color:#ffd6dd;margin:12px 0}
label{display:block;font-weight:600;font-size:13px;margin-bottom:14px}input{width:100%;display:block;background:#0e1829;border:1px solid #3b4a67;border-radius:9px;color:white;padding:11px 13px;margin-top:6px;font:inherit}input:focus{outline:2px solid #7089ec}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:16px}.button{padding:12px 24px;background:#90a9ff;color:#111e38;font-weight:750;border:none;border-radius:10px;cursor:pointer}.button:disabled{opacity:.5;cursor:not-allowed}
.help{padding:12px 16px;background:#1d2b45;border-radius:9px;color:#b4c5e6;font-size:13px}code{color:#d8e3ff;word-break:break-all}
@media(max-width:650px){.checks,.cols{grid-template-columns:1fr}.panel{padding:17px}.top{display:block}}
</style></head><body><div class="wrap">
<div class="top"><div class="brand">▣ AIDrama <span>Setup</span></div><small>单机创作工作台 · 安全安装向导</small></div>
<h1>欢迎安装 AIDrama</h1><p class="muted">PHP 8.4 + MySQL 5.7 · 完整程序和安装器已包含在同一个 ZIP 中，无需额外下载</p>
<div class="pills"><span class="pill">01 验证身份</span><span class="pill">02 环境检查</span><span class="pill">03 配置数据库</span><span class="pill">04 自动安装</span></div>
<?php foreach($errors as $error): ?><div class="notice"><?=esc($error)?></div><?php endforeach; ?>
<?php if(!$unlocked): ?>
<div class="panel"><h2>01 · 验证安装身份</h2><p class="muted">首次访问时已自动生成一次性口令。为防止别人抢先安装，请在宝塔文件管理器打开下方文件，将内容粘贴在这里。此文件不在网站公开目录中。</p>
<div class="help"><code>AIDrama/shared/setup-token.txt</code></div><br>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=esc((string)$_SESSION['aidrama_csrf'])?>"><input type="hidden" name="action" value="unlock">
<label>一次性安装口令<input name="setup_token" type="password" required autocomplete="off" placeholder="粘贴口令"></label>
<button type="submit" class="button">验证并继续 →</button></form></div>
<?php else: ?>
<div class="panel"><h2>02 · 自动检测服务器</h2><div class="checks">
<?php foreach($checks as [$name,$ok]): ?><div class="check"><span class="<?=$ok?'ok':'bad'?>"><?=$ok?'✓':'✕'?></span> <?=esc($name)?></div><?php endforeach; ?>
</div><p class="muted" style="margin:16px 0 0">如果 fileinfo 缺失，请先到宝塔 PHP 8.4 → 安装扩展中启用；如果目录不可写，请将该站点目录所有者设置为 www。</p></div>
<div class="panel"><h2>03 · 网站与数据库配置</h2>
<p class="muted">请先在宝塔创建一个空 MySQL 数据库及专用数据库用户。管理员密码至少 12 位，所有数据将保存在站点公开目录之外。</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=esc((string)$_SESSION['aidrama_csrf'])?>"><input type="hidden" name="action" value="install">
<label>网站访问地址<input name="app_url" type="url" value="<?=esc($defaultUrl)?>" required placeholder="https://drama.example.com"></label>
<div class="cols"><label>数据库地址<input name="db_host" value="127.0.0.1" required></label><label>端口<input name="db_port" type="number" min="1" max="65535" value="3306" required></label></div>
<div class="cols"><label>数据库名<input name="db_name" value="aidrama" required></label><label>数据库用户名<input name="db_user" value="aidrama" required></label></div>
<label>数据库密码<input name="db_password" type="password" required autocomplete="new-password"></label>
<div class="cols"><label>管理员密码（至少12位）<input name="admin_password" type="password" minlength="12" required autocomplete="new-password"></label>
<label>确认管理员密码<input name="admin_confirm" type="password" minlength="12" required autocomplete="new-password"></label></div>
<button type="submit" class="button" <?=$ready?'':'disabled'?>>检查数据库并一键安装 →</button>
</form></div>
<div class="help">你上传的 AIDrama.zip 已包含完整程序及 Composer 依赖；安装时不联网下载额外安装包。数据库密码、API Key 和素材保存在 public 之外，安装成功后自动锁定安装入口。</div>
<?php endif; ?>
</div></body></html>
],['\\\\','\\"','\
}
function install(string $root,array $input): string {
    $appUrl=rtrim(trim((string)($input['app_url']??'')),'/');
    $url=parse_url($appUrl);
    $host=strtolower((string)($url['host']??''));
    if(!$url||!in_array($url['scheme']??'', ['https','http'],true)||!$host||
        isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||
        !in_array(($url['path']??''),['','/'],true) ||
        (($url['scheme']??'')!=='https'&&!in_array($host,['localhost','127.0.0.1'],true)) ||
        strlen($appUrl)>220)fail('网站地址需为 HTTPS 域名（本机 localhost 可用 HTTP），不得包含路径或账号');
    $dbHost=trim((string)($input['db_host']??''));
    $dbName=trim((string)($input['db_name']??''));
    $dbUser=trim((string)($input['db_user']??''));
    $dbPassword=(string)($input['db_password']??'');
    $port=(int)($input['db_port']??3306);
    $admin=(string)($input['admin_password']??'');
    if(!preg_match('/^[a-zA-Z0-9.-]{1,150}$/D',$dbHost) ||
      !preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$dbName) ||
      !preg_match('/^[a-zA-Z0-9_@.-]{1,100}$/D',$dbUser) ||
      $port<1||$port>65535)fail('数据库地址、端口、库名或用户名格式不正确');
    if(strlen($admin)<12||strlen($admin)>256||!hash_equals($admin,(string)($input['admin_confirm']??'')))fail('管理员密码需至少12位且两次一致');
    $pdo=null;
    try{
        $pdo=new PDO("mysql:host={$dbHost};port={$port};dbname={$dbName};charset=utf8mb4",
            $dbUser,$dbPassword,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT=>8,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
            ]);
        $pdo->query('SELECT 1');
        $exists=$pdo->query("SHOW TABLES LIKE 'projects'")->fetchAll();
        if($exists)fail('数据库中已存在 AIDrama 项目表。为了保护数据，安装程序不会覆盖旧数据。');
    } catch(PDOException $e) {
        fail('MySQL 连接失败。请先在宝塔创建空数据库和专用用户，并检查用户名、密码及访问权限。');
    }
    unset($pdo,$dbPassword);
    // The full application is already included in the single ZIP. No internet access required.
    $found=array_values(array_filter(glob($root.'/releases/v*')?:[],
        static fn(string $p):bool => is_dir($p) && preg_match('~^v\d+\.\d+\.\d+$~',basename($p))));
    if(count($found)!==1)fail('安装包不完整：首次安装需且只能包含一个 releases/vX.Y.Z 目录');
    $versionDir=realpath($found[0]);
    if(!$versionDir || is_link($found[0]))fail('发行版本目录不合法');
    $tag=basename($versionDir);
    foreach(['artisan','vendor/autoload.php','bootstrap/app.php','public/index.php','VERSION'] as $required){
        if(!is_file($versionDir.'/'.$required))fail('安装包缺少 '.$required);
    }
    if(trim((string)file_get_contents($versionDir.'/VERSION'))!==$tag)fail('安装包版本不匹配');
    if(file_exists($root.'/shared/.env')||is_file($root.'/shared/install.lock')||
       file_exists($root.'/current')||is_link($root.'/current'))
        fail('检测到已经存在应用配置或版本指针，禁止重复安装');
    makeDir($root.'/shared');
    foreach(['app/private','framework/cache/data','framework/sessions','framework/views','logs'] as $sub){
        makeDir($root.'/shared/storage/'.$sub);
    }
    $key=base64_encode(random_bytes(32));
    $env="APP_NAME=AIDrama\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:{$key}\n".
        "APP_URL=".envQuote($appUrl)."\nAPP_TIMEZONE=Asia/Shanghai\n".
        "LOG_CHANNEL=single\nLOG_LEVEL=warning\n".
        "DB_CONNECTION=mysql\nDB_HOST=".envQuote($dbHost)."\nDB_PORT={$port}\n".
        "DB_DATABASE=".envQuote($dbName)."\nDB_USERNAME=".envQuote($dbUser)."\n".
        "DB_PASSWORD_B64=".base64_encode((string)$input['db_password'])."\n".
        "DB_CHARSET=utf8mb4\nDB_COLLATION=utf8mb4_unicode_ci\n".
        "SESSION_DRIVER=file\nSESSION_LIFETIME=120\n".
        "SESSION_SECURE_COOKIE=".(($url['scheme']??'')==='https'?'true':'false')."\n".
        "CACHE_STORE=file\nQUEUE_CONNECTION=database\n";
    $envFile=$root.'/shared/.env';
    if(file_put_contents($envFile,$env,LOCK_EX)===false)fail('无法保存 .env');
    chmod($envFile,0600);
    if(!symlink($envFile,$versionDir.'/.env') ||
       !symlink($root.'/shared/storage',$versionDir.'/storage'))fail('连接私有配置和存储目录失败');
    makeDir($versionDir.'/bootstrap/cache');
    // Run migrations without invoking shell, Composer, Node or Supervisor.
    require $versionDir.'/vendor/autoload.php';
    $app=require $versionDir.'/bootstrap/app.php';
    $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    if($kernel->call('migrate',['--force'=>true,'--no-interaction'=>true])!==0)fail('数据库迁移失败，请检查 MySQL 权限');
    $hash=password_hash($admin,PASSWORD_DEFAULT);
    $adminFile=$root.'/shared/storage/app/private/admin.hash';
    if(file_put_contents($adminFile,$hash,LOCK_EX)===false)fail('管理员密钥保存失败');
    chmod($adminFile,0600);
    // Atomically enable the already-unpacked application using a private release pointer.
    $next=$root.'/current.next';
    if(is_link($next)||file_exists($next))fail('检测到异常版本指针');
    if(!symlink($versionDir,$next) || !rename($next,$root.'/current'))fail('应用版本切换失败');
    file_put_contents($root.'/shared/install.lock',
        json_encode(['version'=>$tag,'installed_at'=>date(DATE_ATOM)]),LOCK_EX);
    chmod($root.'/shared/install.lock',0600);
    @unlink($root.'/shared/setup-token.txt');
    // The persistent public/index.php is a minimal locked dispatcher, not an installer.
    return $tag;
}
try {
    makeDir($root.'/shared');
    $tokenFile=$root.'/shared/setup-token.txt';
    if(is_file($root.'/shared/install.lock')){http_response_code(404);exit('Installation is locked');}
    if(!is_file($tokenFile)){
        if(file_put_contents($tokenFile,bin2hex(random_bytes(24)),LOCK_EX)===false) fail('无法创建安装口令，请检查目录权限');
        chmod($tokenFile,0600);
    }
    if(empty($_SESSION['aidrama_csrf'])) $_SESSION['aidrama_csrf']=bin2hex(random_bytes(24));
    $post=$_SERVER['REQUEST_METHOD']==='POST';
    if($post){
        $tls=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ||
             strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https';
        $loopback=in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true);
        if(!$tls && !$loopback)fail('请先在宝塔启用 HTTPS，并使用 https:// 地址打开安装页面');

        if(!hash_equals((string)$_SESSION['aidrama_csrf'],(string)($_POST['csrf']??'')))fail('页面已过期，请刷新后重新提交');
        $action=(string)($_POST['action']??'');
        if($action==='unlock'){
            $stored=trim((string)file_get_contents($tokenFile));
            if(!hash_equals($stored,trim((string)($_POST['setup_token']??''))))fail('安装口令不正确');
            session_regenerate_id(true);$_SESSION['aidrama_unlocked']=true;
            $_SESSION['aidrama_csrf']=bin2hex(random_bytes(24));
        }elseif($action==='install'){
            if(empty($_SESSION['aidrama_unlocked']))fail('请先验证安装口令');
            foreach(checks($root) as [$name,$ok])if(!$ok)fail('环境检测未通过：'.$name);
            $lock=fopen($root.'/shared/install-process.lock','c');
            if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))fail('另一个安装请求正在执行');
            set_time_limit(300);
            $tag=install($root,$_POST);
            $success=true;
            $_SESSION=[];
            session_destroy();
        }else fail('无效的操作');
    }
}catch(Throwable $e){
    $errors[]=$e->getMessage();
}
if($success){ header('Location: /login',true,303); exit; }
$unlocked=!empty($_SESSION['aidrama_unlocked']);
$checks=checks($root);
$ready=count(array_filter($checks,static fn($x)=>!$x[1]))===0;
$defaultUrl='https://'.preg_replace('/[^a-zA-Z0-9.\-:]/','',(string)($_SERVER['HTTP_HOST']??'example.com'));
?>
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AIDrama · 首次安装</title><style>
:root{color-scheme:dark;--bg:#0b1220;--panel:#151e31;--line:#2d3c57;--text:#ecf2ff;--muted:#a5b0c6;--blue:#92a8ff}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 10% 0,#233458 0,#0b1220 45%);font-family:system-ui,-apple-system,"Microsoft YaHei",sans-serif;color:var(--text);line-height:1.6}
.wrap{max-width:840px;margin:45px auto 65px;padding:0 18px}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}.brand{font-size:25px;font-weight:750}.brand span{color:#9eb5ff}small,.muted{color:var(--muted)}h1{margin:6px 0;font-size:27px}h2{font-size:18px;margin:0 0 15px}.panel{padding:27px;border:1px solid var(--line);border-radius:18px;background:var(--panel);box-shadow:0 15px 35px #0003;margin-bottom:18px}
.pills{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 28px}.pill{background:#223252;padding:7px 12px;border-radius:30px;font-size:12px;color:#b5c5ed}
.checks{display:grid;grid-template-columns:1fr 1fr;gap:9px}.check{background:#1c2940;border:1px solid #34435f;padding:9px 12px;border-radius:9px;font-size:13px}.ok{color:#66d5b3}.bad{color:#ff9aa6}.notice{background:#5a2933;padding:10px 14px;border-radius:10px;color:#ffd6dd;margin:12px 0}
label{display:block;font-weight:600;font-size:13px;margin-bottom:14px}input{width:100%;display:block;background:#0e1829;border:1px solid #3b4a67;border-radius:9px;color:white;padding:11px 13px;margin-top:6px;font:inherit}input:focus{outline:2px solid #7089ec}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:16px}.button{padding:12px 24px;background:#90a9ff;color:#111e38;font-weight:750;border:none;border-radius:10px;cursor:pointer}.button:disabled{opacity:.5;cursor:not-allowed}
.help{padding:12px 16px;background:#1d2b45;border-radius:9px;color:#b4c5e6;font-size:13px}code{color:#d8e3ff;word-break:break-all}
@media(max-width:650px){.checks,.cols{grid-template-columns:1fr}.panel{padding:17px}.top{display:block}}
</style></head><body><div class="wrap">
<div class="top"><div class="brand">▣ AIDrama <span>Setup</span></div><small>单机创作工作台 · 安全安装向导</small></div>
<h1>欢迎安装 AIDrama</h1><p class="muted">PHP 8.4 + MySQL 5.7 · 完整程序和安装器已包含在同一个 ZIP 中，无需额外下载</p>
<div class="pills"><span class="pill">01 验证身份</span><span class="pill">02 环境检查</span><span class="pill">03 配置数据库</span><span class="pill">04 自动安装</span></div>
<?php foreach($errors as $error): ?><div class="notice"><?=esc($error)?></div><?php endforeach; ?>
<?php if(!$unlocked): ?>
<div class="panel"><h2>01 · 验证安装身份</h2><p class="muted">首次访问时已自动生成一次性口令。为防止别人抢先安装，请在宝塔文件管理器打开下方文件，将内容粘贴在这里。此文件不在网站公开目录中。</p>
<div class="help"><code>AIDrama/shared/setup-token.txt</code></div><br>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=esc((string)$_SESSION['aidrama_csrf'])?>"><input type="hidden" name="action" value="unlock">
<label>一次性安装口令<input name="setup_token" type="password" required autocomplete="off" placeholder="粘贴口令"></label>
<button type="submit" class="button">验证并继续 →</button></form></div>
<?php else: ?>
<div class="panel"><h2>02 · 自动检测服务器</h2><div class="checks">
<?php foreach($checks as [$name,$ok]): ?><div class="check"><span class="<?=$ok?'ok':'bad'?>"><?=$ok?'✓':'✕'?></span> <?=esc($name)?></div><?php endforeach; ?>
</div><p class="muted" style="margin:16px 0 0">如果 fileinfo 缺失，请先到宝塔 PHP 8.4 → 安装扩展中启用；如果目录不可写，请将该站点目录所有者设置为 www。</p></div>
<div class="panel"><h2>03 · 网站与数据库配置</h2>
<p class="muted">请先在宝塔创建一个空 MySQL 数据库及专用数据库用户。管理员密码至少 12 位，所有数据将保存在站点公开目录之外。</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=esc((string)$_SESSION['aidrama_csrf'])?>"><input type="hidden" name="action" value="install">
<label>网站访问地址<input name="app_url" type="url" value="<?=esc($defaultUrl)?>" required placeholder="https://drama.example.com"></label>
<div class="cols"><label>数据库地址<input name="db_host" value="127.0.0.1" required></label><label>端口<input name="db_port" type="number" min="1" max="65535" value="3306" required></label></div>
<div class="cols"><label>数据库名<input name="db_name" value="aidrama" required></label><label>数据库用户名<input name="db_user" value="aidrama" required></label></div>
<label>数据库密码<input name="db_password" type="password" required autocomplete="new-password"></label>
<div class="cols"><label>管理员密码（至少12位）<input name="admin_password" type="password" minlength="12" required autocomplete="new-password"></label>
<label>确认管理员密码<input name="admin_confirm" type="password" minlength="12" required autocomplete="new-password"></label></div>
<button type="submit" class="button" <?=$ready?'':'disabled'?>>检查数据库并一键安装 →</button>
</form></div>
<div class="help">你上传的 AIDrama.zip 已包含完整程序及 Composer 依赖；安装时不联网下载额外安装包。数据库密码、API Key 和素材保存在 public 之外，安装成功后自动锁定安装入口。</div>
<?php endif; ?>
</div></body></html>
],$v).'"';
}
function install(string $root,array $input): string {
    $appUrl=rtrim(trim((string)($input['app_url']??'')),'/');
    $url=parse_url($appUrl);
    $host=strtolower((string)($url['host']??''));
    if(!$url||!in_array($url['scheme']??'', ['https','http'],true)||!$host||
        isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||
        !in_array(($url['path']??''),['','/'],true) ||
        (($url['scheme']??'')!=='https'&&!in_array($host,['localhost','127.0.0.1'],true)) ||
        strlen($appUrl)>220)fail('网站地址需为 HTTPS 域名（本机 localhost 可用 HTTP），不得包含路径或账号');
    $dbHost=trim((string)($input['db_host']??''));
    $dbName=trim((string)($input['db_name']??''));
    $dbUser=trim((string)($input['db_user']??''));
    $dbPassword=(string)($input['db_password']??'');
    $port=(int)($input['db_port']??3306);
    $admin=(string)($input['admin_password']??'');
    if(!preg_match('/^[a-zA-Z0-9.-]{1,150}$/D',$dbHost) ||
      !preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$dbName) ||
      !preg_match('/^[a-zA-Z0-9_@.-]{1,100}$/D',$dbUser) ||
      $port<1||$port>65535)fail('数据库地址、端口、库名或用户名格式不正确');
    if(strlen($admin)<12||strlen($admin)>256||!hash_equals($admin,(string)($input['admin_confirm']??'')))fail('管理员密码需至少12位且两次一致');
    $pdo=null;
    try{
        $pdo=new PDO("mysql:host={$dbHost};port={$port};dbname={$dbName};charset=utf8mb4",
            $dbUser,$dbPassword,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT=>8,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
            ]);
        $pdo->query('SELECT 1');
        $exists=$pdo->query("SHOW TABLES LIKE 'projects'")->fetchAll();
        if($exists)fail('数据库中已存在 AIDrama 项目表。为了保护数据，安装程序不会覆盖旧数据。');
    } catch(PDOException $e) {
        fail('MySQL 连接失败。请先在宝塔创建空数据库和专用用户，并检查用户名、密码及访问权限。');
    }
    unset($pdo,$dbPassword);
    // The full application is already included in the single ZIP. No internet access required.
    $found=array_values(array_filter(glob($root.'/releases/v*')?:[],
        static fn(string $p):bool => is_dir($p) && preg_match('~^v\d+\.\d+\.\d+$~',basename($p))));
    if(count($found)!==1)fail('安装包不完整：首次安装需且只能包含一个 releases/vX.Y.Z 目录');
    $versionDir=realpath($found[0]);
    if(!$versionDir || is_link($found[0]))fail('发行版本目录不合法');
    $tag=basename($versionDir);
    foreach(['artisan','vendor/autoload.php','bootstrap/app.php','public/index.php','VERSION'] as $required){
        if(!is_file($versionDir.'/'.$required))fail('安装包缺少 '.$required);
    }
    if(trim((string)file_get_contents($versionDir.'/VERSION'))!==$tag)fail('安装包版本不匹配');
    if(file_exists($root.'/shared/.env')||is_file($root.'/shared/install.lock')||
       file_exists($root.'/current')||is_link($root.'/current'))
        fail('检测到已经存在应用配置或版本指针，禁止重复安装');
    makeDir($root.'/shared');
    foreach(['app/private','framework/cache/data','framework/sessions','framework/views','logs'] as $sub){
        makeDir($root.'/shared/storage/'.$sub);
    }
    $key=base64_encode(random_bytes(32));
    $env="APP_NAME=AIDrama\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:{$key}\n".
        "APP_URL=".envQuote($appUrl)."\nAPP_TIMEZONE=Asia/Shanghai\n".
        "LOG_CHANNEL=single\nLOG_LEVEL=warning\n".
        "DB_CONNECTION=mysql\nDB_HOST=".envQuote($dbHost)."\nDB_PORT={$port}\n".
        "DB_DATABASE=".envQuote($dbName)."\nDB_USERNAME=".envQuote($dbUser)."\n".
        "DB_PASSWORD_B64=".base64_encode((string)$input['db_password'])."\n".
        "DB_CHARSET=utf8mb4\nDB_COLLATION=utf8mb4_unicode_ci\n".
        "SESSION_DRIVER=file\nSESSION_LIFETIME=120\n".
        "SESSION_SECURE_COOKIE=".(($url['scheme']??'')==='https'?'true':'false')."\n".
        "CACHE_STORE=file\nQUEUE_CONNECTION=database\n";
    $envFile=$root.'/shared/.env';
    if(file_put_contents($envFile,$env,LOCK_EX)===false)fail('无法保存 .env');
    chmod($envFile,0600);
    if(!symlink($envFile,$versionDir.'/.env') ||
       !symlink($root.'/shared/storage',$versionDir.'/storage'))fail('连接私有配置和存储目录失败');
    makeDir($versionDir.'/bootstrap/cache');
    // Run migrations without invoking shell, Composer, Node or Supervisor.
    require $versionDir.'/vendor/autoload.php';
    $app=require $versionDir.'/bootstrap/app.php';
    $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    if($kernel->call('migrate',['--force'=>true,'--no-interaction'=>true])!==0)fail('数据库迁移失败，请检查 MySQL 权限');
    $hash=password_hash($admin,PASSWORD_DEFAULT);
    $adminFile=$root.'/shared/storage/app/private/admin.hash';
    if(file_put_contents($adminFile,$hash,LOCK_EX)===false)fail('管理员密钥保存失败');
    chmod($adminFile,0600);
    // Atomically enable the already-unpacked application using a private release pointer.
    $next=$root.'/current.next';
    if(is_link($next)||file_exists($next))fail('检测到异常版本指针');
    if(!symlink($versionDir,$next) || !rename($next,$root.'/current'))fail('应用版本切换失败');
    file_put_contents($root.'/shared/install.lock',
        json_encode(['version'=>$tag,'installed_at'=>date(DATE_ATOM)]),LOCK_EX);
    chmod($root.'/shared/install.lock',0600);
    @unlink($root.'/shared/setup-token.txt');
    // The persistent public/index.php is a minimal locked dispatcher, not an installer.
    return $tag;
}
try {
    makeDir($root.'/shared');
    $tokenFile=$root.'/shared/setup-token.txt';
    if(is_file($root.'/shared/install.lock')){http_response_code(404);exit('Installation is locked');}
    if(!is_file($tokenFile)){
        if(file_put_contents($tokenFile,bin2hex(random_bytes(24)),LOCK_EX)===false) fail('无法创建安装口令，请检查目录权限');
        chmod($tokenFile,0600);
    }
    if(empty($_SESSION['aidrama_csrf'])) $_SESSION['aidrama_csrf']=bin2hex(random_bytes(24));
    $post=$_SERVER['REQUEST_METHOD']==='POST';
    if($post){
        $tls=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ||
             strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https';
        $loopback=in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true);
        if(!$tls && !$loopback)fail('请先在宝塔启用 HTTPS，并使用 https:// 地址打开安装页面');

        if(!hash_equals((string)$_SESSION['aidrama_csrf'],(string)($_POST['csrf']??'')))fail('页面已过期，请刷新后重新提交');
        $action=(string)($_POST['action']??'');
        if($action==='unlock'){
            $stored=trim((string)file_get_contents($tokenFile));
            if(!hash_equals($stored,trim((string)($_POST['setup_token']??''))))fail('安装口令不正确');
            session_regenerate_id(true);$_SESSION['aidrama_unlocked']=true;
            $_SESSION['aidrama_csrf']=bin2hex(random_bytes(24));
        }elseif($action==='install'){
            if(empty($_SESSION['aidrama_unlocked']))fail('请先验证安装口令');
            foreach(checks($root) as [$name,$ok])if(!$ok)fail('环境检测未通过：'.$name);
            $lock=fopen($root.'/shared/install-process.lock','c');
            if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))fail('另一个安装请求正在执行');
            set_time_limit(300);
            $tag=install($root,$_POST);
            $success=true;
            $_SESSION=[];
            session_destroy();
        }else fail('无效的操作');
    }
}catch(Throwable $e){
    $errors[]=$e->getMessage();
}
if($success){ header('Location: /login',true,303); exit; }
$unlocked=!empty($_SESSION['aidrama_unlocked']);
$checks=checks($root);
$ready=count(array_filter($checks,static fn($x)=>!$x[1]))===0;
$defaultUrl='https://'.preg_replace('/[^a-zA-Z0-9.\-:]/','',(string)($_SERVER['HTTP_HOST']??'example.com'));
?>
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AIDrama · 首次安装</title><style>
:root{color-scheme:dark;--bg:#0b1220;--panel:#151e31;--line:#2d3c57;--text:#ecf2ff;--muted:#a5b0c6;--blue:#92a8ff}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 10% 0,#233458 0,#0b1220 45%);font-family:system-ui,-apple-system,"Microsoft YaHei",sans-serif;color:var(--text);line-height:1.6}
.wrap{max-width:840px;margin:45px auto 65px;padding:0 18px}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}.brand{font-size:25px;font-weight:750}.brand span{color:#9eb5ff}small,.muted{color:var(--muted)}h1{margin:6px 0;font-size:27px}h2{font-size:18px;margin:0 0 15px}.panel{padding:27px;border:1px solid var(--line);border-radius:18px;background:var(--panel);box-shadow:0 15px 35px #0003;margin-bottom:18px}
.pills{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 28px}.pill{background:#223252;padding:7px 12px;border-radius:30px;font-size:12px;color:#b5c5ed}
.checks{display:grid;grid-template-columns:1fr 1fr;gap:9px}.check{background:#1c2940;border:1px solid #34435f;padding:9px 12px;border-radius:9px;font-size:13px}.ok{color:#66d5b3}.bad{color:#ff9aa6}.notice{background:#5a2933;padding:10px 14px;border-radius:10px;color:#ffd6dd;margin:12px 0}
label{display:block;font-weight:600;font-size:13px;margin-bottom:14px}input{width:100%;display:block;background:#0e1829;border:1px solid #3b4a67;border-radius:9px;color:white;padding:11px 13px;margin-top:6px;font:inherit}input:focus{outline:2px solid #7089ec}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:16px}.button{padding:12px 24px;background:#90a9ff;color:#111e38;font-weight:750;border:none;border-radius:10px;cursor:pointer}.button:disabled{opacity:.5;cursor:not-allowed}
.help{padding:12px 16px;background:#1d2b45;border-radius:9px;color:#b4c5e6;font-size:13px}code{color:#d8e3ff;word-break:break-all}
@media(max-width:650px){.checks,.cols{grid-template-columns:1fr}.panel{padding:17px}.top{display:block}}
</style></head><body><div class="wrap">
<div class="top"><div class="brand">▣ AIDrama <span>Setup</span></div><small>单机创作工作台 · 安全安装向导</small></div>
<h1>欢迎安装 AIDrama</h1><p class="muted">PHP 8.4 + MySQL 5.7 · 完整程序和安装器已包含在同一个 ZIP 中，无需额外下载</p>
<div class="pills"><span class="pill">01 验证身份</span><span class="pill">02 环境检查</span><span class="pill">03 配置数据库</span><span class="pill">04 自动安装</span></div>
<?php foreach($errors as $error): ?><div class="notice"><?=esc($error)?></div><?php endforeach; ?>
<?php if(!$unlocked): ?>
<div class="panel"><h2>01 · 验证安装身份</h2><p class="muted">首次访问时已自动生成一次性口令。为防止别人抢先安装，请在宝塔文件管理器打开下方文件，将内容粘贴在这里。此文件不在网站公开目录中。</p>
<div class="help"><code>AIDrama/shared/setup-token.txt</code></div><br>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=esc((string)$_SESSION['aidrama_csrf'])?>"><input type="hidden" name="action" value="unlock">
<label>一次性安装口令<input name="setup_token" type="password" required autocomplete="off" placeholder="粘贴口令"></label>
<button type="submit" class="button">验证并继续 →</button></form></div>
<?php else: ?>
<div class="panel"><h2>02 · 自动检测服务器</h2><div class="checks">
<?php foreach($checks as [$name,$ok]): ?><div class="check"><span class="<?=$ok?'ok':'bad'?>"><?=$ok?'✓':'✕'?></span> <?=esc($name)?></div><?php endforeach; ?>
</div><p class="muted" style="margin:16px 0 0">如果 fileinfo 缺失，请先到宝塔 PHP 8.4 → 安装扩展中启用；如果目录不可写，请将该站点目录所有者设置为 www。</p></div>
<div class="panel"><h2>03 · 网站与数据库配置</h2>
<p class="muted">请先在宝塔创建一个空 MySQL 数据库及专用数据库用户。管理员密码至少 12 位，所有数据将保存在站点公开目录之外。</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=esc((string)$_SESSION['aidrama_csrf'])?>"><input type="hidden" name="action" value="install">
<label>网站访问地址<input name="app_url" type="url" value="<?=esc($defaultUrl)?>" required placeholder="https://drama.example.com"></label>
<div class="cols"><label>数据库地址<input name="db_host" value="127.0.0.1" required></label><label>端口<input name="db_port" type="number" min="1" max="65535" value="3306" required></label></div>
<div class="cols"><label>数据库名<input name="db_name" value="aidrama" required></label><label>数据库用户名<input name="db_user" value="aidrama" required></label></div>
<label>数据库密码<input name="db_password" type="password" required autocomplete="new-password"></label>
<div class="cols"><label>管理员密码（至少12位）<input name="admin_password" type="password" minlength="12" required autocomplete="new-password"></label>
<label>确认管理员密码<input name="admin_confirm" type="password" minlength="12" required autocomplete="new-password"></label></div>
<button type="submit" class="button" <?=$ready?'':'disabled'?>>检查数据库并一键安装 →</button>
</form></div>
<div class="help">你上传的 AIDrama.zip 已包含完整程序及 Composer 依赖；安装时不联网下载额外安装包。数据库密码、API Key 和素材保存在 public 之外，安装成功后自动锁定安装入口。</div>
<?php endif; ?>
</div></body></html>
