<?php
namespace App\Services;

/**
 * The single source of truth for the prompt displayed in the asset editor
 * and sent to both async and synchronous image-generation providers.
 */
final class AssetImagePrompt
{
    public static function build(object $project,object $asset):string
    {
        if((bool)($asset->prompt_override??false)) {
            // A manually saved prompt replaces the generated text exactly.
            return (string)($asset->prompt??'');
        }
        $frame=FrameProfile::effective($project,$asset);
        $kind=match((string)$asset->type) {
            'character'=>'image_character',
            'scene'=>'image_scene',
            'prop'=>'image_prop',
            default=>'image_prop',
        };
        $prompt=PromptPresets::active($kind)."\n\n"
            ."输出画幅：".FrameProfile::prompt($frame)."\n"
            .CreativeLibrary::projectContext($project,'image')."\n"
            ."项目画风：{$project->style}\n资产类别：{$asset->type}\n"
            ."资产名称：{$asset->name}\n视觉设定：{$asset->description}\n"
            ."专业生图要求：统一角色与场景，不添加未说明的品牌、标识或文字。";
        return trim($prompt);
    }
}
