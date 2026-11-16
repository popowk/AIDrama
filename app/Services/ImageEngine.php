<?php
namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ImageEngine
{
    public function generate(object $project,object $asset):array
    {
        if($asset->source_kind==='upload')
            throw new RuntimeException('Logo、品牌产品和真实型号必须上传原始素材，不能由 AI 重新生成。');
        $settings=DB::table('settings')->whereIn('key',[
            'ai.image.api_key','ai.image.api_base','ai.image.model',
        ])->pluck('value','key');
        $encrypted=(string)($settings['ai.image.api_key']??'');
        $model=trim((string)($settings['ai.image.model']??''));
        $base=rtrim((string)($settings['ai.image.api_base']??''),'/');
        if(!$encrypted||!$model||!$base)
            throw new RuntimeException('请先在模型设置中完整填写图片 API、模型名称和密钥');
        try {$key=Crypt::decryptString($encrypted);}
        catch(\Throwable $e){throw new RuntimeException('图片 API Key 无法解密，请重新保存密钥');}
        if(!preg_match('~^https://([a-zA-Z0-9.-]+)(?::443)?(?:/[a-zA-Z0-9._/-]*)?$~D',$base,$m)||
           strtolower($m[1])==='localhost'||str_ends_with(strtolower($m[1]),'.local')||
           filter_var($m[1],FILTER_VALIDATE_IP))
            throw new RuntimeException('图片 API 只允许公网 HTTPS 域名');
        // Admin-configured endpoints must still resolve to public addresses.
        $dns=@dns_get_record($m[1],DNS_A|DNS_AAAA);
        if(!is_array($dns)||!$dns)throw new RuntimeException('图片 API 域名 DNS 解析失败');
        foreach($dns as $item) {
            $ip=$item['ip']??$item['ipv6']??null;
            if($ip&&!filter_var($ip,FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))
                throw new RuntimeException('图片 API 解析到内网或保留地址，已阻止访问');
        }
        $frame=FrameProfile::effective($project,$asset);
        $size=FrameProfile::requestSize($frame,$model);
        $prompt=PromptPresets::active('image')."\n\n"
            ."输出画幅：".FrameProfile::prompt($frame)."\n"
            .CreativeLibrary::projectContext($project,'image')."\n"
            ."项目画风：{$project->style}\n资产类别：{$asset->type}\n"
            ."资产名称：{$asset->name}\n视觉设定：{$asset->description}\n"
            ."专业生图要求：统一角色/场景风格，不添加未说明品牌和文字。";
        if($asset->type==='character')
            $prompt.="\n输出同一角色的正面、侧面、背面三视图，完整全身。";
        $res=Http::withToken($key)->acceptJson()->asJson()
            ->withoutRedirecting()->connectTimeout(15)->timeout(165)
            ->post($base.'/images/generations',[
                'model'=>$model,'prompt'=>$prompt,'n'=>1,'size'=>$size,
            ]);
        if(!$res->successful())
            throw new RuntimeException('图片模型请求失败（HTTP '.$res->status().'），请检查兼容接口、模型或余额');
        $encoded=$res->json('data.0.b64_json');
        if(!is_string($encoded)||$encoded==='')
            throw new RuntimeException('接口未返回 base64 图片；请使用支持 /images/generations 返回 b64_json 的模型');
        if(strlen($encoded)>32*1024*1024)
            throw new RuntimeException('图片响应过大，已拒绝保存');
        $bytes=base64_decode($encoded,true);
        if(!is_string($bytes)||strlen($bytes)>20*1024*1024||strlen($bytes)<64)
            throw new RuntimeException('图片数据无效或超过 20 MB 限额');
        $info=@getimagesizefromstring($bytes);
        $mime=is_array($info)?($info['mime']??''):'';
        $ext=match($mime) {
            'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',
            default=>null,
        };
        if(!$ext||$info[0]>8192||$info[1]>8192)
            throw new RuntimeException('图片格式或尺寸不受支持，仅接收 JPG、PNG、WebP');
        return ['data'=>$bytes,'extension'=>$ext,'mime'=>$mime];
    }
}
