<?php
namespace App\Services;

final class VideoProfile
{
    public const SHORT='short';
    public const STORY='story';
    public static function normalize(?string $mode):string
    {
        return $mode===self::STORY?self::STORY:self::SHORT;
    }
    public static function name(?string $mode):string
    {
        return self::normalize($mode)===self::STORY?'正常故事片':'快节奏短视频';
    }
    public static function musicInstructions(bool $enabled):string
    {
        return $enabled
            ?'背景音乐：允许由创作者根据情节选择适量背景音乐；对白必须清晰优先。'
            :'背景音乐：不生成、不添加背景音乐（BGM），只保留需要的环境声、人物对白和动作音效。';
    }
    public static function dialogueInstructions():string
    {
        return '口播可能由多人交替发言，请严格按照“人物名：台词”逐行识别说话者，不能混为一个人声，不得改词、漏词、换顺序；每个人的声线一致。角色可边做动作边说话。';
    }
}
