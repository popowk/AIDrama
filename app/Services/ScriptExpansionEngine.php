<?php
namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Expands each imported storyboard row without rewriting source dialogue. */
class ScriptExpansionEngine
{
    private function setting(string $key): ?string
    {
        return DB::table('settings')->where('key',$key)->value('value');
    }

    public function expand(string $storyboard,string $style,int $limit,string $ratio='landscape',string $preset='custom',mixed $skills=null): array
    {
        $rows=StoryboardTable::parse($storyboard);
        $apiKey=$this->setting('ai.text.api_key') ?: $this->setting('ai.api_key');
        if(!$apiKey)throw new RuntimeException('请先在「模型设置 → 文本模型」保存 API Key');
        try{$apiKey=Crypt::decryptString($apiKey);}
        catch(\Throwable $e){throw new RuntimeException('文本 API Key 无法解密，请检查 APP_KEY 并重新保存密钥');}
        $base=rtrim($this->setting('ai.text.api_base') ?: $this->setting('ai.api_base') ?: 'https://api.openai.com/v1','/');
        $model=trim($this->setting('ai.text.model') ?: $this->setting('ai.model') ?: '');
        if($model==='')throw new RuntimeException('请先填写文本模型名称');
        if(!preg_match('~^https://([a-zA-Z0-9.-]+)(?::443)?(?:/[a-zA-Z0-9._/-]*)?$~D',$base,$m)
            || strtolower($m[1])==='localhost' || str_ends_with(strtolower($m[1]),'.local')
            || filter_var($m[1],FILTER_VALIDATE_IP))
            throw new RuntimeException('API 地址仅接受公网 HTTPS 域名（不允许 IP、内网或重定向）');

        $system=PromptPresets::active('script')."\n".
            CreativeLibrary::stylePrompt($preset)."\n".
            CreativeLibrary::skillPrompt($skills,'script');
        $prompt="请依据下面已经编排好的分镜表，逐镜扩写成可拍摄的详细剧本，每个分镜输出一段。\n".
            "保持原表顺序和镜头数量，不合并、不删减、不增添镜头；角色、道具、地点不得凭空添加。\n".
            "只扩写镜头画面、动作、镜头调度、衔接，清楚说明先发生什么后说什么。".
            "不要重复输出原始台词或字幕，它们会由程序逐字附加，禁止改写原台词、数值、品牌信息。\n".
            "风格：{$style}；画幅：".FrameProfile::label($ratio)."；".FrameProfile::prompt($ratio).
            "；后续单镜头最长 {$limit} 秒，若已有镜头内容过长，只写连续动作，不修改原台词。\n".
            "每个 script 建议 80-220 个汉字，内容只写本镜的画面与动作，不能加入旁白/对白/字幕。\n".
            "仅输出合法 JSON 对象，格式 {\"segments\":[{\"sequence\":1,\"script\":\"扩写的画面动作\"}]}；".
            "segments 必须与输入逐项对应，sequence 从 1 连续编号。\n".
            "原始分镜表 JSON：\n".json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $res=Http::withToken($apiKey)->acceptJson()->asJson()
            ->withoutRedirecting()->connectTimeout(15)->timeout(145)
            ->retry(2,2000,static function($e):bool {
                return $e instanceof \Illuminate\Http\Client\ConnectionException ||
                    ($e instanceof \Illuminate\Http\Client\RequestException &&
                     in_array($e->response->status(),[408,429,500,502,503,504],true));
            },throw:false)
            ->post($base.'/chat/completions',[
                'model'=>$model,'messages'=>[
                    ['role'=>'system','content'=>$system],
                    ['role'=>'user','content'=>$prompt],
                ],'temperature'=>0.25,
            ]);
        if(!$res->successful())throw new RuntimeException('AI 剧本扩写请求失败（HTTP '.$res->status().'），请检查模型配置和余额');
        $raw=$res->json('choices.0.message.content');
        if(is_array($raw))
            $raw=implode("\n",array_map(static fn($p)=>is_array($p)?(string)($p['text']??''):'',$raw));
        if(!is_string($raw)||trim($raw)==='')throw new RuntimeException('AI 返回空剧本');
        $raw=trim(preg_replace('/^\x60{3}(?:json)?\s*|\s*\x60{3}$/u','',trim($raw)));
        try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
        catch(\JsonException $e){throw new RuntimeException('AI 返回的扩写剧本不是有效 JSON，请重试');}
        $list=$data['segments']??null;
        if(!is_array($list)||count($list)!==count($rows))
            throw new RuntimeException('AI 扩写镜头数量与原始分镜不一致，未保存任何部分结果，请重试');
        $segments=[];
        foreach($rows as $i=>$row) {
            $entry=$list[$i]??null;
            if(!is_array($entry)||(int)($entry['sequence']??0)!==$i+1)
                throw new RuntimeException('AI 返回的镜头顺序异常，未覆盖原有剧本');
            $expanded=trim((string)($entry['script']??''));
            if($expanded==='')throw new RuntimeException('AI 返回了空镜头剧本，未覆盖原有剧本');
            $lines=['画面与动作：'.mb_substr($expanded,0,8000)];
            if($row['dialogue']!=='')$lines[]='原始台词/口播：'.$row['dialogue'];
            if($row['on_screen_text']!=='')$lines[]='画面字幕：'.$row['on_screen_text'];
            if($row['duration']!=='')$lines[]='原始预计时长：'.$row['duration'];
            $segments[]=[
                'sequence'=>$row['sequence'],'title'=>$row['title'],
                'visual'=>$row['visual'],'dialogue'=>$row['dialogue'],
                'on_screen_text'=>$row['on_screen_text'],
                'script'=>implode("\n",$lines),
            ];
        }
        return $segments;
    }
}
