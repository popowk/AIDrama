<?php
namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * External library updates are DATA ONLY. This service never downloads PHP,
 * scripts, archives or executes arbitrary third-party skills. It validates a
 * tiny immutable-schema JSON file from the official AIDrama GitHub repository.
 */
final class LibraryUpdater
{
    public const BUNDLED_VERSION='1.0.0';
    private const RAW_BASE='https://raw.githubusercontent.com/popowk/AIDrama/main/library/';
    private const TYPES=['styles','skills'];
    private const MAX_BYTES=250000;

    private static function ensure(string $type):void
    {
        if(!in_array($type,self::TYPES,true))
            throw new RuntimeException('不支持的素材库类型');
    }

    /** Strict validator for remote and locally installed catalog data. */
    public static function validate(string $type,mixed $data):array
    {
        self::ensure($type);
        if(!is_array($data)||!is_string($data['version']??null)||
           !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$data['version'])||
           !is_array($data['items']??null)||
           count($data['items'])<1||count($data['items'])>120)
            throw new RuntimeException('远程素材库版本或目录格式无效');
        $entries=[];
        foreach($data['items'] as $id=>$entry) {
            if(!is_string($id)||!preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$id)||
               !is_array($entry))
                throw new RuntimeException('素材库存在无效的条目 ID');
            $fields=$type==='styles'
                ?['label'=>100,'category'=>100,'summary'=>400,'prompt'=>9000]
                :['label'=>100,'description'=>400,'prompt'=>9000];
            $clean=[];
            foreach($fields as $field=>$max) {
                $value=$entry[$field]??null;
                if(!is_string($value)||trim($value)===''||mb_strlen($value)>$max||
                   preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))
                    throw new RuntimeException('素材库条目 '. $id .' 的 '. $field .' 无效');
                $clean[$field]=trim($value);
            }
            if($type==='skills') {
                $stages=$entry['stages']??null;
                if(!is_array($stages)||count($stages)<1||count($stages)>3||
                   count(array_filter($stages,'is_string'))!==count($stages)||
                   count($stages)!==count(array_unique($stages))||
                   array_diff($stages,['script','image','video']))
                    throw new RuntimeException('素材库技能 '.$id.' 使用了不支持的应用阶段');
                $clean['stages']=array_values($stages);
            }
            $entries[$id]=$clean;
        }
        return ['version'=>$data['version'],'items'=>$entries];
    }

    private function storagePath(string $type):string
    {
        self::ensure($type);
        $disk=Storage::disk('local');
        return $disk->path('library/'.$type.'.json');
    }

    public function local(string $type):?array
    {
        $file=$this->storagePath($type);
        if(!is_file($file)||is_link($file)||(@filesize($file)?:0)>self::MAX_BYTES)
            return null;
        try{
            $data=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
            $catalog=self::validate($type,$data);
            return version_compare($catalog['version'],self::BUNDLED_VERSION,'>')?$catalog:null;
        }catch(\Throwable $e){
            return null; // A corrupted local overlay never breaks project pages.
        }
    }

    public function current(string $type):array
    {
        self::ensure($type);
        $local=$this->local($type);
        return ['version'=>$local['version']??self::BUNDLED_VERSION,
            'count'=>count(($type==='styles')?CreativeLibrary::STYLES:CreativeLibrary::SKILLS)
              +count(array_diff_key($local['items']??[],
                ($type==='styles')?CreativeLibrary::STYLES:CreativeLibrary::SKILLS))];
    }

    public function activeItems(string $type):array
    {
        $local=$this->local($type);
        $base=($type==='styles')?CreativeLibrary::STYLES:CreativeLibrary::SKILLS;
        // Overwrite only matching named fields; preserve bundled catalog IDs.
        return array_replace($base,$local['items']??[]);
    }

    private function fetchRemote(string $type):array
    {
        self::ensure($type);
        $curl=curl_init(self::RAW_BASE.$type.'.json');
        if(!$curl)throw new RuntimeException('无法初始化 GitHub 素材库检查');
        curl_setopt_array($curl,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>7,
            CURLOPT_TIMEOUT=>14,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'AIDrama-Library-Updater/1',
            CURLOPT_HTTPHEADER=>['Accept: application/json'],
        ]);
        $body=curl_exec($curl);
        $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
        curl_close($curl);
        if(!is_string($body)||$status!==200)
            throw new RuntimeException('获取 GitHub '.($type==='styles'?'风格库':'技能库').'失败（HTTP '.$status.'）');
        if(strlen($body)>self::MAX_BYTES)
            throw new RuntimeException('远程素材库内容过大，已拒绝下载');
        try{$parsed=json_decode($body,true,512,JSON_THROW_ON_ERROR);}
        catch(\JsonException $e){throw new RuntimeException('远程素材库不是有效 JSON');}
        return self::validate($type,$parsed);
    }

    public function check(?string $only=null):array
    {
        if($only!==null)self::ensure($only);
        $result=[];
        foreach($only!==null?[$only]:self::TYPES as $type) {
            $local=$this->current($type);
            try {
                $remote=$this->fetchRemote($type);
                $result[$type]=[
                    'current'=>$local['version'],'latest'=>$remote['version'],
                    'available'=>version_compare($remote['version'],$local['version'],'>'),
                    'count'=>$local['count'],'new_count'=>count($remote['items']),
                ];
            } catch(\Throwable $e) {
                $result[$type]=['current'=>$local['version'],'latest'=>null,
                    'available'=>false,'count'=>$local['count'],
                    'error'=>$e instanceof RuntimeException?$e->getMessage():'检查失败'];
            }
        }
        return $result;
    }

    public function apply(string $type):array
    {
        self::ensure($type);
        $remote=$this->fetchRemote($type);
        $installed=$this->current($type);
        if(!version_compare($remote['version'],$installed['version'],'>'))
            return ['status'=>'latest','version'=>$installed['version'],
                'message'=>($type==='styles'?'风格库':'技能库').'已是最新版本'];

        $path=$this->storagePath($type);
        $folder=dirname($path);
        if(!is_dir($folder) && !mkdir($folder,0750,true) && !is_dir($folder))
            throw new RuntimeException('无法创建私有素材库更新目录');
        if(is_link($folder)||is_link($path))
            throw new RuntimeException('拒绝写入符号链接素材库目录');
        $tmp=$folder.'/.catalog-'.bin2hex(random_bytes(10)).'.tmp';
        try {
            $content=json_encode($remote,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
            if(file_put_contents($tmp,$content,LOCK_EX)===false)
                throw new RuntimeException('无法保存私有素材库更新文件');
            @chmod($tmp,0600);
            // Atomic swap. Existing custom prompt settings and assets are never touched.
            if(!rename($tmp,$path))
                throw new RuntimeException('无法激活新素材库文件');
        }finally{@unlink($tmp);}
        return ['status'=>'done','version'=>$remote['version'],
            'message'=>($type==='styles'?'风格库':'技能库').'已更新到 '.$remote['version'].
            '，已保留项目设置、自定义提示词和原有素材'];
    }
}
