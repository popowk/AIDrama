<?php
namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StoryboardEngine
{
    private string $preferredMode='auto';
    public function setMode(string $mode):void {
        $this->preferredMode=in_array($mode,['auto','talking','mixed'],true)?$mode:'auto';
    }
    private function setting(string $key): ?string
    {
        return DB::table('settings')->where('key',$key)->value('value');
    }

    public function generate(string $script, string $style, int $limit): array
    {
        $apiKey=$this->setting('ai.text.api_key') ?: $this->setting('ai.api_key');
        if (!$apiKey) throw new RuntimeException('请先在「模型设置 → 文本模型」保存 API Key');
        try { $apiKey=Crypt::decryptString($apiKey); }
        catch (\Throwable $e) { throw new RuntimeException('文本 API Key 无法解密，请检查 APP_KEY 并重新保存密钥'); }

        $base=rtrim($this->setting('ai.text.api_base') ?: $this->setting('ai.api_base') ?: 'https://api.openai.com/v1','/');
        $model=trim($this->setting('ai.text.model') ?: $this->setting('ai.model') ?: '');
        if ($model==='') throw new RuntimeException('请先填写文本模型名称');
        // Private-network and redirect protection; the admin may configure public HTTPS OpenAI-compatible APIs.
        if (!preg_match('~^https://([a-zA-Z0-9.-]+)(?::443)?(?:/[a-zA-Z0-9._/-]*)?$~D',$base,$m)
            || strtolower($m[1])==='localhost' || str_ends_with(strtolower($m[1]),'.local')
            || filter_var($m[1],FILTER_VALIDATE_IP)) {
            throw new RuntimeException('API 地址仅接受公网 HTTPS 域名（不允许 IP、内网或重定向）');
        }
        $skills=PromptPresets::active('script');
        $mode=$this->preferredMode;
        $prompt="请按完整剧本输出分镜、画面文案及人物、场景、道具清单。\n".
          "风格：{$style}；每镜最长：{$limit}秒；创作类型偏好：{$mode}（auto 为模型识别）。\n".
          "script_mode 只能 talking 或 mixed：talking 表示主要对镜头讲话的纯口播；mixed 表示画面文案、情节/产品展示配合口播或画外音。\n".
          "镜头字段：title, visual, dialogue, action_before, estimated_seconds, on_screen_text, delivery。".
          "on_screen_text 是画面中应显示的文字；delivery 为 on_camera、voiceover 或 none。\n".
          "角色、场景和道具字段：type (character|scene|prop), name, description, voice_description, source_kind (upload|generate)。".
          "Logo、品牌标识、真实产品和具体型号/包装等 source_kind 必须是 upload，并独立列入资产；其他可生成的人物、非品牌场景与道具为 generate。\n".
          "仅输出 JSON：".
          '{"script_mode":"talking|mixed","shots":[{"title":"镜头","visual":"画面动作","dialogue":"口播","action_before":"开口前动作","on_screen_text":"画面字幕或空","delivery":"on_camera|voiceover|none","estimated_seconds":8}],'.
          '"assets":[{"type":"character|scene|prop","name":"名称","description":"外观","voice_description":"人物声音","source_kind":"upload|generate"}]}'.
          "\n台词和动作按发生顺序，禁止添加剧本中未出现的角色，不要遗漏原稿。\n剧本：\n".$script;
        $res=Http::withToken($apiKey)->acceptJson()->asJson()
            ->withoutRedirecting()->connectTimeout(15)->timeout(145)
            ->retry(2,2000,static function($exception): bool {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException ||
                    ($exception instanceof \Illuminate\Http\Client\RequestException &&
                     in_array($exception->response->status(),[408,429,500,502,503,504],true));
            },throw:false)
            ->post($base.'/chat/completions',[
                'model'=>$model,
                'messages'=>[['role'=>'system','content'=>$skills],['role'=>'user','content'=>$prompt]],
                'temperature'=>0.3,
            ]);
        if (!$res->successful()) throw new RuntimeException('AI 接口请求失败（HTTP '.$res->status().'），请检查模型配置和余额');
        $raw=$res->json('choices.0.message.content');
        if (is_array($raw)) {
            $raw=implode("\n",array_map(static fn($p)=>is_array($p)?(string)($p['text']??''):'',$raw));
        }
        if (!is_string($raw) || trim($raw)==='') throw new RuntimeException('AI 返回了空内容');
        $raw=trim(preg_replace('/^.*?\\x60\\x60\\x60(?:json)?\\s*|\\s*\\x60\\x60\\x60\\s*$/su','',$raw));
        try { $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new RuntimeException('AI 返回的分镜不是有效 JSON，请重试'); }
        if (!is_array($data) || empty($data['shots']) || !is_array($data['shots'])) {
            throw new RuntimeException('AI 返回的 JSON 缺少 shots 分镜数组');
        }
        $detected=$mode!=='auto'?$mode:(string)($data['script_mode']??'');
        if(!in_array($detected,['talking','mixed'],true))
            $detected=preg_match('/纯口播|面对镜头|口播稿|对镜头讲话/u',$script)&&
                !preg_match('/画面文案|字幕展示|旁白|画外音|B-roll/u',$script)
                ?'talking':'mixed';
        return ['shots'=>$this->splitShots($data['shots'],$style,$limit,$detected),
            'assets'=>$this->normalizeAssets($data['assets']??[], $style),
            'script_mode'=>$detected];
    }

    private function splitShots(array $items, string $style, int $limit,string $mode): array
    {
        $result=[];
        foreach (array_slice($items,0,120) as $item) {
            if (!is_array($item)) continue;
            $title=mb_substr(trim((string)($item['title']??'新镜头')),0,190);
            $visual=trim((string)($item['visual']??''));
            if ($visual==='') continue;
            $dialogue=trim((string)($item['dialogue']??''));
            $pre=trim((string)($item['action_before']??''));
            $onScreen=mb_substr(trim((string)($item['on_screen_text']??'')),0,5000);
            $delivery=(string)($item['delivery']??($mode==='talking'?'on_camera':'voiceover'));
            if(!in_array($delivery,['on_camera','voiceover','none'],true))
                $delivery=$mode==='talking'?'on_camera':'voiceover';
            $estimated=max(3,min(300,(int)($item['estimated_seconds']??6)));
            // For speaking shots reserve a little time for pre-dialogue movements.
            // 3.5 Chinese characters/sec is an editable planning estimate, not audio measurement.
            $maxChars=max(8,(int)floor(($limit-($pre!==''?3:1))*3.5));
            $parts=$dialogue!==''?$this->splitDialogue($dialogue,$maxChars):[''];
            if ($dialogue==='' && $estimated>$limit) $parts=array_fill(0,(int)ceil($estimated/$limit),'');
            foreach ($parts as $i=>$part) {
                $partPre=$i===0?$pre:'';
                $duration=$part!==''?max(3,(int)ceil(mb_strlen(preg_replace('/[\\s，。！？!?、；：:“”"‘’]/u','',$part))/3.5)+($partPre!==''?3:1)):(int)ceil($estimated/count($parts));
                $duration=min($limit,max(3,$duration));
                $shotTitle=$title.(count($parts)>1?' · '.($i+1).'/'.count($parts):'');
                $result[]=[
                    'title'=>$shotTitle,'visual'=>$visual.($i>0?'（承接上一镜头，人物位置和动作保持连续）':''),
                    'dialogue'=>$part,'action_before'=>$partPre,
                    'on_screen_text'=>$onScreen,'delivery'=>$delivery,
                    'duration_seconds'=>$duration,
                    'prompt'=>"画风：{$style}\n镜头：{$shotTitle}\n画面：{$visual}\n".
                        ($partPre!==''?"先完成：{$partPre}\n":'').
                        ($part!==''?"对白：{$part}\n":'').
                        ($onScreen!==''?"画面文字：{$onScreen}\\n":'').
                        "表达方式：{$delivery}\\n".
                        "时长：{$duration}秒。保持角色服装、场景、光线和前后镜头连续性。",
                ];
                if (count($result)>=500) break 2;
            }
        }
        if (!$result) throw new RuntimeException('AI 未返回有效镜头');
        return $result;
    }

    private function splitDialogue(string $text,int $maxChars): array
    {
        if (mb_strlen($text)<=$maxChars) return [$text];
        $sentences=preg_split('/(?<=[。！？!?；;])\\s*/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[$text];
        $parts=[];$buffer='';
        foreach($sentences as $s) {
            while(mb_strlen($s)>$maxChars) {
                if ($buffer!=='') { $parts[]=$buffer; $buffer=''; }
                $parts[]=mb_substr($s,0,$maxChars); $s=mb_substr($s,$maxChars);
            }
            if (mb_strlen($buffer.$s)>$maxChars && $buffer!=='') { $parts[]=$buffer; $buffer=''; }
            $buffer.=$s;
        }
        if ($buffer!=='') $parts[]=$buffer;
        return $parts?:[$text];
    }

    private function normalizeAssets(mixed $assets,string $style): array
    {
        if (!is_array($assets)) return [];
        $result=[];$seen=[];
        foreach (array_slice($assets,0,100) as $asset) {
            if (!is_array($asset)) continue;
            $type=(string)($asset['type']??'');
            if (!in_array($type,['character','scene','prop'],true)) continue;
            $name=mb_substr(trim((string)($asset['name']??'')),0,190);
            if ($name==='' || isset($seen[$type.'|'.$name])) continue;
            $seen[$type.'|'.$name]=true;
            $desc=trim((string)($asset['description']??''));
            $hint=(string)($asset['source_kind']??'');
            $source=AssetOrigin::needsUpload($type,$name,$desc,$hint)?'upload':'generate';
            $result[]=['type'=>$type,'name'=>$name,'description'=>$desc,
                'source_kind'=>$source,'image_status'=>'pending',
                'voice_description'=>$type==='character'?(string)($asset['voice_description']??''):null,
                'prompt'=>"{$style}，".($type==='character'?'人物三视图：正面、左侧面、背面，白色背景，完整全身，角色一致性。':($type==='scene'?'环境概念图，广角建立镜头，无人物：':'道具设计图，清晰产品细节：')).$name.'。'.$desc];
        }
        return $result;
    }
}
