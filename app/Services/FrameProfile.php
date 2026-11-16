<?php
namespace App\Services;

class FrameProfile
{
    public const FORMATS=[
        'landscape'=>['label'=>'横屏 16:9','prompt'=>'16:9 横向构图','size'=>'1536x864','fallback'=>'1536x1024'],
        'portrait'=>['label'=>'竖屏 9:16','prompt'=>'9:16 纵向构图','size'=>'864x1536','fallback'=>'1024x1536'],
        'square'=>['label'=>'方形 1:1','prompt'=>'1:1 方形构图','size'=>'1024x1024','fallback'=>'1024x1024'],
    ];

    public static function effective(object $project,?object $asset=null):string
    {
        $value=$asset->frame_ratio??'inherit';
        if(!is_string($value)||!array_key_exists($value,self::FORMATS))
            $value=(string)($project->frame_ratio??'landscape');
        return array_key_exists($value,self::FORMATS)?$value:'landscape';
    }

    public static function label(string $ratio):string
    {
        return self::FORMATS[$ratio]['label']??self::FORMATS['landscape']['label'];
    }

    public static function prompt(string $ratio):string
    {
        return (self::FORMATS[$ratio]['prompt']??self::FORMATS['landscape']['prompt'])
            .'；从生成时就按照目标宽高比布局，不是事后随意裁切；主体、人物三视图、边缘细节和关键物品完整放在画面安全区，给屏幕文案留出适当空间。';
    }

    public static function requestSize(string $ratio,string $model):string
    {
        $f=self::FORMATS[$ratio]??self::FORMATS['landscape'];
        // Image 2.x allows arbitrary 16-pixel multiples (including exact 16:9).
        // Legacy GPT image families accept only their documented standard
        // landscape / portrait sizes; retain the ratio in the prompt.
        $exact=(bool)preg_match('/^gpt-image-2(?:\.|$|-)/i',trim($model));
        return $exact?$f['size']:$f['fallback'];
    }
}
