<?php
namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use ZipArchive;

class ReleaseManager
{
    private const REPO='popowk/AIDrama';
    private const ZIP='AIDrama.zip';
    private const CHECKSUM='AIDrama.zip.sha256';

    private function root(): string
    {
        $app=realpath(base_path()) ?: base_path();
        if (!preg_match('~^(.+)/releases/v\d+\.\d+\.\d+$~D',$app,$m))
            throw new RuntimeException('网页更新仅支持已安装的 GitHub 正式版 AIDrama');
        $root=$m[1];
        if(!is_file($root.'/shared/install.lock')||!is_dir($root.'/public'))
            throw new RuntimeException('安装目录或安装状态异常');
        return $root;
    }

    public function current(): string
    {
        $root=$this->root();
        $file=$root.'/shared/active-version';
        $tag=is_file($file)?trim((string)file_get_contents($file)):'';
        if(!preg_match('~^v\d+\.\d+\.\d+$~D',$tag))
            throw new RuntimeException('当前版本标记缺失，无法安全更新');
        return $tag;
    }

    private function release(): array
    {
        $curl=curl_init('https://api.github.com/repos/'.self::REPO.'/releases/latest');
        curl_setopt_array($curl,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>18,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json'],
            CURLOPT_USERAGENT=>'AIDrama-Web-Updater/1',
        ]);
        $body=curl_exec($curl);
        $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
        curl_close($curl);
        if(!is_string($body)||$status!==200)
            throw new RuntimeException('无法读取 GitHub 最新 Release，HTTP '.$status);
        $release=json_decode($body,true);
        if(!is_array($release)||!preg_match('~^v\d+\.\d+\.\d+$~D',(string)($release['tag_name']??'')))
            throw new RuntimeException('GitHub 返回的版本信息无效');
        return $release;
    }

    public function check(): array
    {
        $current=$this->current();
        $latest=$this->release();
        $tag=(string)$latest['tag_name'];
        return [
            'current'=>$current,
            'latest'=>$tag,
            'available'=>version_compare(substr($tag,1),substr($current,1),'>'),
            'release_url'=>'https://github.com/'.self::REPO.'/releases/tag/'.$tag,
        ];
    }

    private function setState(string $root,string $status,string $message,string $version=''): void
    {
        $file=$root.'/shared/update-state.json';
        $tmp=$file.'.new-'.bin2hex(random_bytes(4));
        $body=json_encode([
            'status'=>$status,'message'=>$message,'version'=>$version,
            'updated_at'=>date(DATE_ATOM),
        ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$body,LOCK_EX)===false)throw new RuntimeException('无法保存更新状态');
        @chmod($tmp,0600);
        if(!rename($tmp,$file))throw new RuntimeException('无法更新进度状态');
    }

    public function status(): array
    {
        $root=$this->root();
        $file=$root.'/shared/update-state.json';
        $data=is_file($file)?json_decode((string)file_get_contents($file),true):null;
        return is_array($data)?$data:['status'=>'idle','message'=>'尚未执行更新'];
    }

    private function download(string $url,string $target): void
    {
        $prefix='https://github.com/'.self::REPO.'/releases/download/';
        if(!str_starts_with($url,$prefix))throw new RuntimeException('拒绝非官方的下载地址');
        $fp=@fopen($target,'wb');
        if(!$fp)throw new RuntimeException('无法写入更新下载缓存');
        $curl=curl_init($url);
        curl_setopt_array($curl,[
            CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,
            CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>240,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'AIDrama-Web-Updater/1',
        ]);
        if(defined('CURLOPT_REDIR_PROTOCOLS_STR'))
            curl_setopt($curl,CURLOPT_REDIR_PROTOCOLS_STR,'https');
        else
            curl_setopt($curl,CURLOPT_REDIR_PROTOCOLS,CURLPROTO_HTTPS);
        $ok=curl_exec($curl);
        $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
        curl_close($curl);fclose($fp);
        if(!$ok||$status!==200)throw new RuntimeException('下载 GitHub 安装包失败，HTTP '.$status);
    }

    private function inspectZip(string $file): ZipArchive
    {
        $z=new ZipArchive;
        if($z->open($file)!==true)throw new RuntimeException('安装包 ZIP 无法打开');
        if($z->numFiles>30000)throw new RuntimeException('安装包文件数量异常');
        $size=0;
        for($i=0;$i<$z->numFiles;$i++) {
            $meta=$z->statIndex($i);
            $p=(string)($meta['name']??'');
            if($p===''||str_starts_with($p,'/')||str_contains($p,'\\')||
                str_contains($p,"\0")||preg_match('~(^|/)\.\.?(/|$)~',$p)||
                str_starts_with($p,'shared/')||str_starts_with($p,'public/storage/')||
                str_starts_with($p,'public/.')||
                preg_match('~^public/.*\.(?:env|sql|sqlite|log|bak|zip|ini)$~i',$p))
                throw new RuntimeException('安装包含有不安全的公开文件或路径');
            $size+=(int)($meta['size']??0);
            if($size>2147483648)throw new RuntimeException('安装包解压体积超限');
            if($z->getExternalAttributesIndex($i,$os,$attrs) &&
               $os===ZipArchive::OPSYS_UNIX && (($attrs>>16)&0170000)===0120000)
                throw new RuntimeException('安装包不能包含软链接');
        }
        return $z;
    }

    public function apply(): array
    {
        $root=$this->root();
        $lock=@fopen($root.'/shared/update-process.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))
            throw new RuntimeException('已有更新正在运行，请查看更新状态');
        ignore_user_abort(true);
        @set_time_limit(360);
        $tag='';
        try {
            $this->setState($root,'checking','检查最新正式版本');
            $current=$this->current();
            $release=$this->release();
            $tag=(string)$release['tag_name'];
            if(version_compare(substr($tag,1),substr($current,1),'<='))
                return $this->finish($root,'done','已经是最新正式版本',$current);
            $assets=[];
            foreach(($release['assets']??[]) as $asset)
                $assets[$asset['name']??'']=$asset['browser_download_url']??'';
            if(empty($assets[self::ZIP])||empty($assets[self::CHECKSUM]))
                throw new RuntimeException('最新 Release 缺少 ZIP 或 SHA256 校验文件');
            $dir=$root.'/releases/'.$tag;
            if(!is_dir($dir)) {
                $temp=$root.'/shared/tmp/update-'.bin2hex(random_bytes(8));
                if(!is_dir($temp)&&!mkdir($temp,0750,true)&&!is_dir($temp))
                    throw new RuntimeException('无法创建私有下载目录');
                $archive=$temp.'/AIDrama.zip';
                $checksum=$temp.'/AIDrama.zip.sha256';
                $this->setState($root,'downloading','正在下载完整更新包',$tag);
                $this->download($assets[self::ZIP],$archive);
                $this->download($assets[self::CHECKSUM],$checksum);
                $this->setState($root,'verifying','正在验证安装包的 SHA256',$tag);
                $line=trim((string)file_get_contents($checksum));
                if(!preg_match('/^([a-fA-F0-9]{64})\s+\*?AIDrama\.zip$/D',$line,$m) ||
                    !hash_equals(strtolower($m[1]),hash_file('sha256',$archive)))
                    throw new RuntimeException('安装包校验失败，未切换程序版本');
                $z=$this->inspectZip($archive);
                $unpack=$temp.'/unpacked';
                if(!mkdir($unpack,0750,true)||!$z->extractTo($unpack))
                    throw new RuntimeException('安装包解压失败');
                $z->close();
                $stage=$unpack.'/releases/'.$tag;
                foreach(['VERSION','artisan','bootstrap/app.php','vendor/autoload.php','database/migrations'] as $required)
                    if(!file_exists($stage.'/'.$required))throw new RuntimeException('完整安装包缺少 '.$required);
                if(trim((string)file_get_contents($stage.'/VERSION'))!==$tag)
                    throw new RuntimeException('安装包内部版本号不匹配');
                $this->setState($root,'extracting','正在预备新版本程序',$tag);
                if(!rename($stage,$dir))throw new RuntimeException('无法保存新版本代码');
                @unlink($archive);@unlink($checksum);
            }
            if(!is_file($dir.'/vendor/autoload.php')||
                !is_file($dir.'/bootstrap/app.php')||
                !is_file($dir.'/public/app.css')||
                trim((string)@file_get_contents($dir.'/VERSION'))!==$tag)
                throw new RuntimeException('待更新版本文件不完整，拒绝切换');

            // Explicit administrator click authorizes pending migrations.
            // Existing MySQL data is never deleted by this updater.
            $this->setState($root,'migrating','正在应用新版本数据库迁移。升级前建议已备份数据库',$tag);
            $result=Artisan::call('migrate',[
                '--path'=>$dir.'/database/migrations',
                '--realpath'=>true,'--force'=>true,'--no-interaction'=>true,
            ]);
            if($result!==0)throw new RuntimeException('数据库迁移失败，未切换当前程序版本');

            $this->setState($root,'activating','正在切换正式版本',$tag);
            $marker=$root.'/shared/active-version';
            $next=$root.'/shared/active-version.new-'.bin2hex(random_bytes(4));
            if(file_put_contents($next,$tag."\n",LOCK_EX)===false)
                throw new RuntimeException('无法写入版本标记');
            @chmod($next,0600);
            if(!rename($next,$marker))throw new RuntimeException('版本标记切换失败');
            @file_put_contents($root.'/previous-release',$current,LOCK_EX);
            $css=$root.'/public/app-css-'.bin2hex(random_bytes(4));
            if(copy($dir.'/public/app.css',$css))
                if(!rename($css,$root.'/public/app.css'))@unlink($css);
            return $this->finish($root,'done','已更新到 '.$tag.'，数据库与素材均已保留',$tag);
        } catch(\Throwable $e) {
            $message=$e instanceof RuntimeException?$e->getMessage():'更新中出现服务器错误，请检查 Laravel 日志';
            $this->setState($root,'failed',$message,$tag);
            throw new RuntimeException($message,0,$e);
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
    }

    private function finish(string $root,string $state,string $message,string $tag): array
    {
        $this->setState($root,$state,$message,$tag);
        return ['status'=>$state,'message'=>$message,'version'=>$tag];
    }
}
