<?php
namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StoryboardEngine
{
    private string $preferredMode='auto';
    private string $videoMode='short';
    private string $frameRatio='landscape';
    private string $stylePreset='custom';
    private mixed $creativeSkills=null;
    public function setCreativeContext(string $ratio,string $stylePreset,mixed $skills):void
    {
        $this->frameRatio=in_array($ratio,['landscape','portrait'],true)?$ratio:'landscape';
        $this->stylePreset=array_key_exists($stylePreset,CreativeLibrary::STYLES)?$stylePreset:'custom';
        $this->creativeSkills=$skills;
    }
    public function setVideoMode(string $mode):void {
        $this->videoMode=VideoProfile::normalize($mode);
    }
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
        $skills=PromptPresets::active('script')."\n".
            CreativeLibrary::stylePrompt($this->stylePreset)."\n".
            CreativeLibrary::skillPrompt($this->creativeSkills,'script');
        $mode=$this->preferredMode;
        $short=$this->videoMode==='short';
        $profileRule=$short
            ?"【短视频默认模式】尽可能缩短总时长并加快节奏；同一个场景内相邻的动作、眼神、表情和台词可合并为一个镜头，不因单独表情或轻微动作切镜。人物可以一边做动作一边说话；除非必须先完成动作才可以说话，否则 action_before 留空并把同步动作写进 visual。估时真实紧凑，只在空间或人物调度不连续时切镜。每镜可有两个角色轮流说话，dialogue 按“悟空：...\\n八戒：...”多行形式保留发言顺序。每镜返回同一场景的 scene 字段供合并判断；禁止生成字幕。"
            :"【正常故事片模式】保持原有完整叙事、情绪铺垫、画面调度和镜头拆分方式，不强行压缩和合并；允许镜头表达表情和先后动作。";
        $prompt="请按剧本输出分镜、画面文案及人物、场景、道具清单。\n".$profileRule."\n"."请按完整剧本输出分镜、画面文案及人物、场景、道具清单。\n".
          "风格：{$style}；画幅：".FrameProfile::label($this->frameRatio).
          "，".FrameProfile::prompt($this->frameRatio)."；每镜最长：{$limit}秒；创作类型偏好：{$mode}（auto 为模型识别）。\n".
          "script_mode 只能 talking 或 mixed：talking 表示主要对镜头讲话的纯口播；mixed 表示画面文案、情节/产品展示配合口播或画外音。\n".
          "镜头字段：title, visual, dialogue, action_before, estimated_seconds, on_screen_text, delivery。".
          "on_screen_text 是画面中应显示的文字；delivery 为 on_camera、voiceover 或 none。\n".
          "镜头可选字段 scene：连续地点布景的固定名称；同场景多个镜头使用完全一致的 scene。对白允许多个说话人，dialogue 同一字段逐行标注‘人物：台词’，不得更改顺序。\n".
          "角色、场景和道具字段：type (character|scene|prop), name, description, voice_description, source_kind (upload|generate)。".
          "Logo、品牌标识、真实产品和具体型号/包装等 source_kind 必须是 upload，并独立列入资产；其他可生成的人物、非品牌场景与道具为 generate。\n".
          "仅输出 JSON：".
          '{"script_mode":"talking|mixed","shots":[{"title":"镜头","scene":"场景名","visual":"画面动作","dialogue":"悟空：台词\\n八戒：台词","action_before":"必须先做的动作或空","on_screen_text":"画面文案或空","delivery":"on_camera|voiceover|none","estimated_seconds":8}],'.
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
        $shots=$this->splitShots($data['shots'],$style,$limit,$detected);
        if($short)$shots=$this->compactShots($shots,$limit);
        foreach($shots as &$shot)unset($shot['scene_key']);
        unset($shot);
        return ['shots'=>$shots,
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
            $short=$this->videoMode==='short';
            $scene=mb_substr(trim((string)($item['scene']??'')),0,190);
            if($short && $pre!=='' && !preg_match('/(?:必须|先完成|完成后|之后才|在.*之前)/u',$pre)){
                $visual.="；人物可在「{$pre}」的同时自然说话";
                $pre='';
            }
            $onScreen=$short?'':mb_substr(trim((string)($item['on_screen_text']??'')),0,5000);
            $delivery=(string)($item['delivery']??($mode==='talking'?'on_camera':'voiceover'));
            if(!in_array($delivery,['on_camera','voiceover','none'],true))
                $delivery=$mode==='talking'?'on_camera':'voiceover';
            $estimated=max(3,min(300,(int)($item['estimated_seconds']??6)));
            // For speaking shots reserve a little time for pre-dialogue movements.
            // 3.5 Chinese characters/sec is an editable planning estimate, not audio measurement.
            $maxChars=max(8,(int)floor(($limit-($pre!==''?3:($short?0:1)))*3.5));
            $parts=$dialogue!==''?$this->splitDialogue($dialogue,$maxChars):[''];
            if ($dialogue==='' && $estimated>$limit) $parts=array_fill(0,(int)ceil($estimated/$limit),'');
            foreach ($parts as $i=>$part) {
                $partPre=$i===0?$pre:'';
                $spoken=preg_replace('/(?:^|\\n)[^\\n：:]{1,25}[：:]/u',"\\n",$part);
                $duration=$part!==''?max(3,(int)ceil(mb_strlen(preg_replace('/[\\s，。！？!?、；：:“”"‘’]/u','',$spoken))/3.5)+($partPre!==''?3:($short?0:1))):(int)ceil($estimated/count($parts));
                $duration=min($limit,max(3,$duration));
                $shotTitle=$title.(count($parts)>1?' · '.($i+1).'/'.count($parts):'');
                $result[]=[
                    'title'=>$shotTitle,'scene_key'=>$scene,
                    'visual'=>$visual.($i>0?'（承接上一镜头，人物位置和动作保持连续）':''),
                    'dialogue'=>$part,'action_before'=>$partPre,
                    'on_screen_text'=>$onScreen,'delivery'=>$delivery,
                    'duration_seconds'=>$duration,
                    'prompt'=>"画风：{$style}\n画幅：".FrameProfile::prompt($this->frameRatio).
                        "\n镜头：{$shotTitle}\n画面：{$visual}\n".
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

    /**
     * Merge adjacent short-video beats only when the model explicitly tags
     * the same continuous scene and the combined duration fits the limit.
     */
    private function compactShots(array $shots,int $limit):array
    {
        $combined=[];
        foreach($shots as $shot){
            $last=count($combined)-1;
            if($last>=0){
                $prev=$combined[$last];
                $scene=trim((string)($shot['scene_key']??''));
                $compatible=$scene!==''&&$scene===(string)($prev['scene_key']??'')
                    &&($prev['delivery']??'')===($shot['delivery']??'')
                    &&$prev['duration_seconds']+$shot['duration_seconds']<=$limit;
                if($compatible){
                    $combined[$last]['title']=mb_substr($prev['title'].'＋'.$shot['title'],0,190);
                    $combined[$last]['visual']=$prev['visual']."\n同镜连续完成：".$shot['visual'];
                    $combined[$last]['dialogue']=trim($prev['dialogue']."\n".$shot['dialogue']);
                    $combined[$last]['action_before']=trim($prev['action_before']."\n".$shot['action_before']);
                    $combined[$last]['duration_seconds']=$prev['duration_seconds']+$shot['duration_seconds'];
                    $combined[$last]['on_screen_text']='';
                    $combined[$last]['prompt']=trim($prev['prompt']."\n连续动作：".$shot['prompt']);
                    continue;
                }
            }
            $combined[]=$shot;
        }
        return $combined;
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
                'source_kind'=>$source,'image_status'=>'pending','frame_ratio'=>'inherit',
                'voice_description'=>$type==='character'?(string)($asset['voice_description']??''):null,
                'prompt'=>"{$style}，".($type==='character'?'人物三视图：正面、左侧面、背面，白色背景，完整全身，角色一致性。':($type==='scene'?'环境概念图，广角建立镜头，无人物：':'道具设计图，清晰产品细节：')).$name.'。'.$desc];
        }
        return $result;
    }
}
