<?php
namespace App\Services;
class PromptBuilder {
    public function build(object $project, object $shot, string $target): string {
        $target=in_array($target,['kling','seedance','stable-diffusion'],true)?$target:'kling';
        $file=base_path('skills/'.$target.'.md');
        $rules=is_file($file)?trim((string)file_get_contents($file)):'';
        $body="项目画风：{$project->style}\n镜头 {$shot->sequence}：{$shot->title}\n".
            "画面：{$shot->visual}\n".
            ($shot->action_before?"先发生的动作：{$shot->action_before}\n":'').
            ($shot->dialogue?"台词：{$shot->dialogue}\n":'').
            "目标时长：{$shot->duration_seconds}秒（镜头上限 {$project->shot_limit} 秒）。\n".
            "要求：人物脸型、服装、道具和场景前后统一；动作按照时间顺序；不可凭空增加人物。";
        return trim($rules."\n\n".$body);
    }
}
