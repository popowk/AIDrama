<?php
namespace App\Services;

/**
 * Per-shot sound staging is separate from script_mode: a mixed short film can
 * alternate between addressing the camera, natural character dialogue,
 * voiceover and silence.
 */
final class ShotDelivery
{
    public const OPTIONS=['on_camera','conversation','voiceover','none'];

    public static function normalize(mixed $value,string $dialogue='',string $visual='',string $fallback='voiceover'):string
    {
        $delivery=is_string($value)?trim($value):'';
        if(!in_array($delivery,self::OPTIONS,true))
            $delivery=in_array($fallback,self::OPTIONS,true)?$fallback:'voiceover';

        // Respect explicit voiceover and silence. Only automatically classify
        // camera speech as natural conversation when the shot itself proves it.
        if($delivery!=='on_camera'||trim($dialogue)==='')
            return $delivery;
        if(preg_match('/(?:面向|面对|正对|对着|看向|直视)\s*镜头|对镜头讲话|向观众讲述/u',$visual))
            return 'on_camera';

        preg_match_all('/(?:^|\R)\s*([^：:\r\n]{1,28})\s*[：:]/u',$dialogue,$matches);
        $speakers=array_values(array_unique(array_map(
            static fn($name)=>trim((string)$name),$matches[1]??[]
        )));
        if(count($speakers)>=2)
            return 'conversation';
        if(preg_match('/(?:互相交谈|对话|交流|相互回应|两人交谈|看向对方|朝对方说话|转头回应|对[^。；\r\n]{1,30}(?:说|问|答)|朝[^。；\r\n]{1,20}(?:说|喊|问))/u',$visual))
            return 'conversation';
        return 'on_camera';
    }

    public static function label(string $delivery):string
    {
        return match($delivery){
            'conversation'=>'人物之间对话（看向对方）',
            'voiceover'=>'画外音 / 旁白',
            'none'=>'无口播',
            default=>'人物面对镜头说话',
        };
    }

    public static function direction(string $delivery):string
    {
        return match($delivery){
            'conversation'=>'人物面对彼此自然交流，视线看向对话对象而不是摄像机；根据说话人顺序同步口型、表情和回应动作。短视频优先同镜完成，可使用双人同框或自然的过肩构图，不额外拆镜。',
            'on_camera'=>'人物明确面向摄像机讲话，视线适当看向镜头；仅这种模式要求对镜头口播。',
            'voiceover'=>'声音为画外旁白；画面中的人物无需看向镜头或对嘴，保留正常剧情动作。',
            'none'=>'本镜没有对白、口播或画外旁白；用画面和需要的环境声推进情节。',
            default=>'',
        };
    }
}
