<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class PromptBuilder
{
    public function build(object $project,object $shot,string $target):string
    {
        $target=in_array($target,['kling','seedance','stable-diffusion'],true)?$target:'kling';
        if($target==='stable-diffusion') {
            $filename=base_path('skills/stable-diffusion.md');
            $rules=is_file($filename)?trim((string)file_get_contents($filename)):'';
        } else {
            $rules=PromptPresets::active($target);
        }
        $mode=$project->detected_mode ?: ($project->script_mode==='auto'?'mixed':$project->script_mode);
        $modeName=$mode==='talking'?'纯口播':'文案/镜头画面配合口播';
        $voice=match((string)($shot->delivery??'on_camera')) {
            'voiceover'=>'画外音 / 旁白','none'=>'无台词','on_camera'=>'角色面对镜头说话',
            default=>'角色面对镜头说话',
        };
        $frame=(string)($project->frame_ratio??'landscape');
        $visualSkills=CreativeLibrary::projectContext($project,'video');
        $body="项目风格：{$project->style}\n画面比例：".FrameProfile::label($frame).
            "，".FrameProfile::prompt($frame)."\n".
            ($visualSkills!==''?"风格与技能：".$visualSkills."\n":'').
            "创作方式：{$modeName}\n".
            "镜头 {$shot->sequence}：{$shot->title}\n画面与运动：{$shot->visual}\n".
            ($shot->action_before?"先完成：{$shot->action_before}\n":'').
            ($shot->dialogue?"{$voice}：{$shot->dialogue}\n":'').
            (!empty($shot->on_screen_text)?"屏幕文案：{$shot->on_screen_text}\n":'').
            "目标时长：{$shot->duration_seconds} 秒，实际遵循所使用视频模型支持的时长。\n".
            "要求：镜头动作与台词时间顺序准确，人物形象、服装和场景连贯。";
        $assets=DB::table('assets')->where('project_id',$project->id)
            ->where('source_kind','upload')->orderBy('id')->get(['name','file_path']);
        if($assets->isNotEmpty()) {
            $body.="\n真实品牌/产品素材（严禁 AI 自行复原）：";
            foreach($assets as $a)
                $body.="\n- {$a->name}：".($a->file_path
                    ?'客户已上传，视频创作时须附带该参考图并保持外观和 Logo 一致'
                    :'尚未上传，禁止生成包含其真实外观的产品画面');
        }
        return trim($rules."\n\n".$body);
    }
}
