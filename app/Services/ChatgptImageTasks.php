<?php
namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The supported chatgpt2api 3.x API: submit a durable idempotent image task,
 * then poll for results using short independent HTTP connections.
 *
 * Never proxy arbitrary user-supplied URLs; downloads must use the same
 * public HTTPS origin as the configured image API and the /files/ route.
 */
class ChatgptImageTasks
{
    private ?array $connection=null;

    public function config():array
    {
        if($this->connection!==null)return $this->connection;
        $settings=DB::table('settings')->whereIn('key',[
            'ai.image.api_base','ai.image.api_key','ai.image.model',
        ])->pluck('value','key');
        $base=rtrim(trim((string)($settings['ai.image.api_base']??'')),'/');
        $model=trim((string)($settings['ai.image.model']??''));
        $encrypted=(string)($settings['ai.image.api_key']??'');
        if(!$base||!$model||!$encrypted)
            throw new RuntimeException('请先完整填写图片模型的 API Base URL、模型名称与 API Key');
        try {$key=Crypt::decryptString($encrypted);}
        catch(\Throwable $e){throw new RuntimeException('图片 API Key 解密失败，请在设置中重新保存');}
        $parts=parse_url($base);
        $host=is_array($parts)?strtolower((string)($parts['host']??'')):'';
        if(!$parts||($parts['scheme']??'')!=='https'||!$host||
           isset($parts['user'])||isset($parts['pass'])||
           isset($parts['query'])||isset($parts['fragment'])||
           (isset($parts['port'])&&(int)$parts['port']!==443)||
           $host==='localhost'||str_ends_with($host,'.local')||
           filter_var($host,FILTER_VALIDATE_IP))
            throw new RuntimeException('图片 API 仅支持有效的公网 HTTPS 域名及默认 443 端口');
        $this->checkPublicDns($host);
        $apiRoot=preg_replace('~/v1$~','',$base);
        if($apiRoot===$base)
            throw new RuntimeException('chatgpt2api 图片 API Base URL 需以 /v1 结尾');
        return $this->connection=[
            'base'=>$base,'root'=>$apiRoot,'host'=>$host,'key'=>$key,'model'=>$model,
        ];
    }

    private function checkPublicDns(string $host):void
    {
        $records=@dns_get_record($host,DNS_A|DNS_AAAA);
        if(!is_array($records)||!$records)
            throw new RuntimeException('图片 API 域名无法解析，请检查 DNS');
        foreach($records as $record) {
            $ip=$record['ip']??$record['ipv6']??null;
            if($ip&&!filter_var($ip,FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))
                throw new RuntimeException('图片 API DNS 返回内网或保留地址，拒绝访问');
        }
    }

    private function client():\Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->config()['key'])->acceptJson()->asJson()
            ->withoutRedirecting()->connectTimeout(8)->timeout(18);
    }

    private function error($response,string $operation):void
    {
        if(!$response->successful())
            throw new RuntimeException($operation.'失败（HTTP '.$response->status().'），请检查 chatgpt2api 任务接口和服务日志');
    }

    public function submit(string $taskId,object $project,object $asset):array
    {
        $config=$this->config();
        if(!preg_match('/^aidrama-[a-f0-9]{32}$/D',$taskId))
            throw new RuntimeException('无效的图片任务编号');
        $frame=FrameProfile::effective($project,$asset);
        $size=FrameProfile::requestSize($frame,$config['model']);
        $prompt=AssetImagePrompt::build($project,$asset);
        $response=$this->client()->post($config['root'].'/api/image-tasks/generations',[
            'client_task_id'=>$taskId,
            'model'=>$config['model'],'prompt'=>$prompt,
            'n'=>1,'size'=>$size,'quality'=>'auto',
        ]);
        $this->error($response,'提交异步图片任务');
        $item=$response->json();
        if(!is_array($item)||($item['id']??'')!==$taskId)
            throw new RuntimeException('chatgpt2api 返回的任务编号与提交编号不匹配');
        return $item;
    }

    public function check(string $taskId):?array
    {
        $config=$this->config();
        $response=$this->client()->get($config['root'].'/api/image-tasks',[
            'ids'=>$taskId,
        ]);
        $this->error($response,'查询异步图片任务');
        $data=$response->json();
        if(!is_array($data)||!is_array($data['items']??null))
            throw new RuntimeException('chatgpt2api 图片任务查询响应结构无效');
        foreach($data['items'] as $item)
            if(is_array($item)&&($item['id']??'')===$taskId)
                return $item;
        return null;
    }

    public function resume(string $taskId):array
    {
        $config=$this->config();
        $response=$this->client()->post(
            $config['root'].'/api/image-tasks/'.rawurlencode($taskId).'/resume-poll',
            ['extra_timeout_secs'=>120]
        );
        $this->error($response,'恢复图片结果查询');
        $data=$response->json();
        if(!is_array($data)||($data['id']??'')!==$taskId)
            throw new RuntimeException('恢复任务时返回了无效的任务编号');
        return $data;
    }

    /**
     * chatgpt2api often publishes http://localhost:3000/images/... when
     * behind a TLS-terminating reverse proxy. Never request that advertised
     * origin. Extract a narrowly validated image path and fetch it ONLY from
     * the configured public HTTPS API origin on port 443.
     *
     * Supports:
     *  - /images/... or /files/... relative links
     *  - http(s) URLs on the configured host (even if the internal port leaks)
     *  - loopback URLs produced by chatgpt2api's default local base_url
     * Unknown public domains, redirects, traversal and other schemes remain
     * rejected. This is an address rewrite, not an SSRF allowlist expansion.
     */
    private function trustedDownloadUrl(string $value):string
    {
        $config=$this->config();
        $value=trim($value);
        if($value===''||strlen($value)>4096||
           preg_match('/[\x00-\x1f\x7f\s]/',$value))
            throw new RuntimeException('chatgpt2api 返回的图片 URL 为空、过长或含非法字符');

        if(str_starts_with($value,'/') && !str_starts_with($value,'//')) {
            $parts=parse_url('https://'.$config['host'].$value);
        }elseif(str_starts_with($value,'//')) {
            $parts=parse_url('https:'.$value);
        }else{
            $parts=parse_url($value);
        }

        $scheme=is_array($parts)?strtolower((string)($parts['scheme']??'')):'';
        $host=is_array($parts)?strtolower((string)($parts['host']??'')):'';
        $path=is_array($parts)?(string)($parts['path']??''):'';
        $query=is_array($parts)?(string)($parts['query']??''):'';
        if(!$parts||!in_array($scheme,['http','https'],true)||!$host||
           isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))
            throw new RuntimeException('chatgpt2api 图片 URL 的协议或结构不受支持（只接受 HTTP(S) 图片路径）');
        if(!preg_match('~^/(?:images|files)/[A-Za-z0-9_./-]+$~D',$path)||
           str_contains($path,'..'))
            throw new RuntimeException('图片 URL 的文件路径不在可信 /images/ 或 /files/ 目录内');
        if(strlen($query)>1024||($query!==''&&!preg_match('/^[A-Za-z0-9._~%=&+;:-]+$/D',$query)))
            throw new RuntimeException('图片 URL 带有不支持的查询参数');

        // Never send a request to the URL's advertised host: only the one
        // already validated and configured by the administrator is used.
        $originHost=trim($host,'[]');
        $localHost=in_array($originHost,['localhost','127.0.0.1','::1'],true)
            || (filter_var($originHost,FILTER_VALIDATE_IP)!==false
                && filter_var($originHost,FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false);
        if($host!==$config['host']&&!$localHost)
            throw new RuntimeException(
                '图片结果的域名与配置的 API 域名不一致；请核对 chatgpt2api 的 image_storage.public_base_url'
            );
        $this->checkPublicDns($config['host']);
        return 'https://'.$config['host'].$path.($query!==''?'?'.$query:'');
    }

    public function image(array $remoteTask):array
    {
        $item=$remoteTask['results'][0]??null;
        if(!is_array($item))
            throw new RuntimeException('异步任务已完成但未返回有效图片结果');
        $encoded=(string)($item['b64_json']??'');
        if($encoded!=='') {
            if(strlen($encoded)>32*1024*1024)
                throw new RuntimeException('图片结果超过允许大小');
            $bytes=base64_decode($encoded,true);
            if(!is_string($bytes))throw new RuntimeException('图片 base64 解码失败');
        } else {
            $url=$this->trustedDownloadUrl((string)($item['url']??''));
            // The files endpoint serves public random file paths. Do not send
            // bearer tokens to file URLs, even on the same API host.
            $response=Http::accept('image/png,image/jpeg,image/webp')
                ->withoutRedirecting()->connectTimeout(8)->timeout(26)
                ->withOptions(['stream'=>true])->get($url);
            $this->error($response,'下载生成图片');
            $body=$response->toPsrResponse()->getBody();
            $bytes='';
            while(!$body->eof()) {
                $bytes.=$body->read(65536);
                if(strlen($bytes)>20*1024*1024)
                    throw new RuntimeException('图片结果超过 20MB，拒绝保存');
            }
        }
        if(strlen($bytes)<64||strlen($bytes)>20*1024*1024)
            throw new RuntimeException('图片字节长度无效或超过 20MB');
        $info=@getimagesizefromstring($bytes);
        if(!is_array($info)||($info[0]??0)>8192||($info[1]??0)>8192)
            throw new RuntimeException('图片数据损坏或分辨率过大');
        $mime=$info['mime']??'';
        $ext=match($mime){
            'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp',
            default=>null
        };
        if(!$ext)throw new RuntimeException('生成图片仅支持 PNG/JPEG/WebP');
        return ['data'=>$bytes,'extension'=>$ext,'mime'=>$mime];
    }
}
