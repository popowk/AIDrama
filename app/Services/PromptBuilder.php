<?php
namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PromptBuilder
{
    /**
     * A deterministic grounding brief. The generate() method sends this
     * to the configured text LLM; merely clicking the UI is no longer a
     * string-concatenation shortcut.
     */
    public function build(object $project,object $shot,string $target):string
    {
        $target=in_array($target,['kling','seedance','stable-diffusion'],true)?$target:'kling';
        if($target==='stable-diffusion'){
            $file=base_path('skills/stable-diffusion.md');
            $rules=is_file($file)?trim((string)file_get_contents($file)):'';
        }else{$rules=PromptPresets::active($target);}
        $mode=$project->detected_mode?:($project->script_mode==='auto'?'mixed':$project->script_mode);
        $delivery=(string)($shot->delivery??'on_camera');
        $voice=ShotDelivery::label($delivery);
        $voiceDirection=ShotDelivery::direction($delivery);
        $profile=VideoProfile::normalize($project->video_mode??'short');
        $short=$profile==='short';
        $frame=(string)($project->frame_ratio??'landscape');
        $body="项目风格：{$project->style}\n画面比例：".FrameProfile::label($frame)
            ."，".FrameProfile::prompt($frame)."\n"
            ."创作方式：".VideoProfile::name($profile)."，".($mode==='talking'?'纯口播':'剧情 / 产品展示结合对白')."\n";
        $visualSkills=CreativeLibrary::projectContext($project,'video');
        if($visualSkills!=='')$body.="风格与技能：{$visualSkills}\n";
        $body.="镜头 {$shot->sequence}：{$shot->title}\n"
            ."画面与运动：{$shot->visual}\n";
        if($shot->action_before)
            $body.="开口前必须完成的动作：".$shot->action_before."\n";
        $body.="声音呈现：{$voice}\n";
        if($voiceDirection!=='')$body.="人物视线、声音和动作调度：{$voiceDirection}\n";
        if($shot->dialogue)
            $body.="{$voice}（逐行区分角色）：{$shot->dialogue}\n";
        $body.="目标时长：{$shot->duration_seconds}秒，服装、场景、道具和人物保持一致。\n";
        $body.=$short
            ?'快节奏短视频：动作、表情、眼神变化与对白可以同步推进，尽量同镜完成；不为了微小动作或表情单独切镜。'
            :'正常故事片：保留原来的叙事节奏、镜头调度和情绪铺垫。';
        $body.="\n".VideoProfile::dialogueInstructions()."\n";
        $body.="字幕：不要生成字幕、画面文字、水印或额外的叠加文案。\n";
        $body.=VideoProfile::musicInstructions((bool)($project->allow_background_music??false));
        $references=AssetReferencePicker::guidance($project,$shot);
        if($references!=='')$body.="\n".$references;
        return trim($rules."\n\n".$body);
    }

    /** Actual model-assisted prompt writing (not a local template return). */
    public function generate(object $project,object $shot,string $target):string
    {
        $basePrompt=$this->build($project,$shot,$target);
        $settings=DB::table('settings')
            ->whereIn('key',['ai.text.api_key','ai.api_key','ai.text.api_base','ai.api_base',
                'ai.text.model','ai.model'])->pluck('value','key');
        $encrypted=(string)($settings['ai.text.api_key']??$settings['ai.api_key']??'');
        $base=rtrim((string)($settings['ai.text.api_base']??$settings['ai.api_base']??'https://api.openai.com/v1'),'/');
        $model=trim((string)($settings['ai.text.model']??$settings['ai.model']??''));
        if(!$encrypted||!$model)throw new RuntimeException('请在模型设置中完整配置文本大模型及 API Key');
        try{$key=Crypt::decryptString($encrypted);}
        catch(\Throwable $e){throw new RuntimeException('文本模型 API Key 解密失败，请重新保存');}
        if(!preg_match('~^https://([a-zA-Z0-9.-]+)(?::443)?(?:/[a-zA-Z0-9._/-]*)?$~D',$base,$m)
            ||strtolower($m[1])==='localhost'||str_ends_with(strtolower($m[1]),'.local')
            ||filter_var($m[1],FILTER_VALIDATE_IP))
            throw new RuntimeException('文本模型地址必须是公网 HTTPS 域名');

        $response=Http::withToken($key)->acceptJson()->asJson()
            ->withoutRedirecting()->connectTimeout(12)->timeout(145)
            ->post($base.'/chat/completions',[
                'model'=>$model,'temperature'=>0.25,
                'messages'=>[
                    ['role'=>'system','content'=>'你是专业视频提示词导演。把用户给出的分镜信息写成可直接用于视频生成模型的高质量提示词，保持原始事实、人物、台词、型号、画幅和时长。不输出解释，不编造参考图已上传的事实。不生成字幕、画面文字或水印。根据分镜声音呈现区分：conversation 为人物对人物自然交流、看向对方而不是镜头；on_camera 才是人物对镜头口播；voiceover 为画外音。对白严格保留“人物名：台词”的发言顺序，不要自动加入背景音乐。必须保留全部提供参考图的需求。'],
                    ['role'=>'user','content'=>"请针对 {$target} 使用以下镜头事实和提示词模板撰写最终提示词。请直接返回纯文本。参考图片清单、无字幕/背景音乐规则和逐字对白由系统在末尾统一附加，不要再次罗列“请提供 XX 图片”条目或重复输出规则。不要声称用户未上传图片，不能假装图片已经作为附件发送。\n\n".$basePrompt],
                ],
            ]);
        if(!$response->successful())
            throw new RuntimeException('文本模型处理视频提示词失败（HTTP '.$response->status().'），请检查模型和余额');
        $raw=$response->json('choices.0.message.content');
        if(is_array($raw))$raw=implode("\n",array_map(static fn($x)=>is_array($x)?(string)($x['text']??''):'',$raw));
        if(!is_string($raw)||trim($raw)==='')
            throw new RuntimeException('文本模型没有返回有效视频提示词');
        $text=$this->cleanModelOutput(trim($raw));
        // Ground essential references and policies outside of the model output:
        // the model cannot silently drop active asset references or no-BGM.
        $references=AssetReferencePicker::guidance($project,$shot);
        $guard="\n\n【生成约束】\n"
            ."本镜声音呈现：".ShotDelivery::label((string)($shot->delivery??'on_camera'))."。".ShotDelivery::direction((string)($shot->delivery??'on_camera'))."\n"
            ."不生成任何字幕、叠加文字或水印。\n"
            .VideoProfile::musicInstructions((bool)($project->allow_background_music??false))."\n"
            .VideoProfile::dialogueInstructions();
        if(trim((string)($shot->dialogue??''))!=='')
            $guard.="\n【必须逐字保留的对白（依次发言）】\n".$shot->dialogue;
        if($references!=='')$guard.="\n".$references;
        return trim(mb_substr($text,0,30000).$guard);
    }
    private function cleanModelOutput(string $text):string
    {
        // The application appends one authoritative asset-reference list.
        // Remove standalone copies from the model; preserve scene/action
        // sentences even if the model incorrectly mentions a missing upload.
        $lines=preg_split('/\R/u',$text)?:[$text];
        $output=[];
        foreach($lines as $line){
            $trimmed=trim($line);
            if(preg_match('/^(?:[-*]\s*)?请提供[「“"][^」”"]+[」”"]图片(?:（[^）]*）)?[。.]?$/u',$trimmed))
                continue;
            if(preg_match('/^(?:本镜参考资产图片|参考资产图片要求)(?:[（(:：].*)?$/u',$trimmed))
                continue;
            $line=(string)preg_replace(
                '/(?:尚未上传|未上传图片|缺少参考图|没有上传)/u',
                '需要提供参考图片',$line
            );
            $output[]=$line;
        }
        return trim(implode("\n",$output));
    }

}
