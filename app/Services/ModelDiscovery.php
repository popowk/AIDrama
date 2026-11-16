<?php
namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Detects OpenAI-compatible /v1/models without persisting a probe key or
 * trusting redirects, private network endpoints or arbitrary provider errors.
 * Listing a model does NOT guarantee endpoint/capability/credit availability.
 */
final class ModelDiscovery
{
    public function discover(string $kind,string $base,?string $suppliedKey):array
    {
        if(!in_array($kind,['text','image'],true))
            throw new RuntimeException('模型类别无效');

        $base=rtrim(trim($base),'/');
        $parts=parse_url($base);
        $host=is_array($parts)?strtolower((string)($parts['host']??'')):'';
        $path=is_array($parts)?(string)($parts['path']??''):'';
        if(!is_array($parts)||($parts['scheme']??'')!=='https'||$host===''||
           isset($parts['user'])||isset($parts['pass'])||
           isset($parts['query'])||isset($parts['fragment'])||
           (isset($parts['port'])&&(int)$parts['port']!==443)||
           !preg_match('~^/[A-Za-z0-9._/-]*$~D',$path)||
           $host==='localhost'||str_ends_with($host,'.local')||
           filter_var($host,FILTER_VALIDATE_IP))
            throw new RuntimeException('模型列表检测仅接受公网 HTTPS 域名，不允许 IP、非 443 端口或 URL 参数');

        $saved=DB::table('settings')->whereIn('key',[
            'ai.'.$kind.'.api_base','ai.'.$kind.'.api_key',
            'ai.api_base','ai.api_key',
        ])->pluck('value','key');
        $storedBase=(string)($saved['ai.'.$kind.'.api_base']??'');
        if($kind==='text'&&$storedBase==='')$storedBase=(string)($saved['ai.api_base']??'');
        $key=trim((string)$suppliedKey);
        if($key==='') {
            if($storedBase===''||rtrim($storedBase,'/')!==$base)
                throw new RuntimeException('接口地址已修改，请先填写新 API Key 后检测；保存过的密钥仅用于原来的接口地址');
            $encrypted=(string)($saved['ai.'.$kind.'.api_key']??'');
            if($kind==='text'&&$encrypted==='')$encrypted=(string)($saved['ai.api_key']??'');
            if($encrypted==='')throw new RuntimeException('请先输入 API Key 或保存模型配置后检测');
            try{$key=Crypt::decryptString($encrypted);}
            catch(\Throwable $e){throw new RuntimeException('已保存的 API Key 无法解密，请重新输入');}
        }

        $records=@dns_get_record($host,DNS_A|DNS_AAAA);
        if(!is_array($records)||!$records)throw new RuntimeException('模型 API 域名 DNS 解析失败');
        $public=[];
        foreach($records as $record){
            $ip=$record['ip']??$record['ipv6']??null;
            if(!$ip)continue;
            if(!filter_var($ip,FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))
                throw new RuntimeException('模型 API 域名解析到内网或保留地址，拒绝检测');
            $public[]=$ip;
        }
        if(!$public)throw new RuntimeException('模型 API 域名没有可用的公网 DNS 记录');

        // Pin the resolved public address to prevent DNS rebinding between
        // validation and the HTTPS request. TLS hostname verification remains on.
        $ip=$public[0];
        $address=str_contains($ip,':')?'['.$ip.']':$ip;
        $request=Http::withToken($key)->acceptJson()->withoutRedirecting()
            ->connectTimeout(6)->timeout(15)
            ->withOptions(['curl'=>[CURLOPT_RESOLVE=>[$host.':443:'.$address]]]);

        try{$response=$request->get($base.'/models');}
        catch(\Throwable $e){
            throw new RuntimeException('无法连接模型列表接口，请检查 API 域名、网络和证书');
        }

        if(!$response->successful())
            throw new RuntimeException('模型列表检测失败（HTTP '.$response->status().'）。此服务商可能不支持 /models，可继续手动填写');
        if(strlen($response->body())>1048576)
            throw new RuntimeException('模型列表响应过大，已停止解析');
        $list=$response->json('data');
        if(!is_array($list))
            throw new RuntimeException('API 未返回标准 data 模型列表，可手动输入模型名');

        $ids=[];
        foreach(array_slice($list,0,500) as $entry) {
            $id=is_array($entry)?($entry['id']??null):null;
            if(!is_string($id))continue;
            $id=trim($id);
            if($id===''||mb_strlen($id)>191||preg_match('/[\x00-\x1f\x7f]/',$id))continue;
            $ids[$id]=$id;
        }
        if(!$ids)throw new RuntimeException('模型列表为空，暂时无法选择，请手动输入模型名称');
        $all=array_values($ids);
        $imageName=static fn(string $id):bool=>(bool)preg_match(
            '/gpt[-_.]?image|dall[-_.]?e|image|imagen|flux|seedream|ideogram|qwen[-_.]?image/i',$id
        );
        $relevant=array_values(array_filter($all,
            $kind==='image'?$imageName:static fn($name)=>!$imageName($name)));
        if(!$relevant)$relevant=$all;
        return [
            'models'=>$relevant,
            'all_models'=>$all,
            'kind'=>$kind,
            'hint'=>count($all).' 个接口报告的模型。列表仅证明模型名称已公开，不代表一定支持当前生图/文本操作。',
        ];
    }
}
