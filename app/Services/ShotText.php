<?php
namespace App\Services;

/**
 * One editable shot description, with stable internal visual/voice fields.
 * The two optional headings live INSIDE the same textarea, never in separate
 * UI inputs, and avoid treating arbitrary "监控画面：" labels as speakers.
 */
final class ShotText
{
    public const BEFORE='【开口前动作】';
    public const SPEECH='【台词 / 旁白】';

    public static function format(object $shot):string
    {
        $parts=[trim((string)($shot->visual??''))];
        $before=trim((string)($shot->action_before??''));
        $dialogue=trim((string)($shot->dialogue??''));
        if($before!=='')$parts[]=self::BEFORE."\n".$before;
        if($dialogue!=='')$parts[]=self::SPEECH."\n".$dialogue;
        return implode("\n\n",array_filter($parts,static fn($part)=>$part!==''));
    }

    /**
     * Parse only exact full-line editor delimiters. Do not infer the meaning
     * of general text before a colon (e.g. 监控画面： or 画面呈现：).
     */
    public static function parse(string $content):array
    {
        $content=str_replace(["\r\n","\r"],"\n",$content);
        $parts=preg_split(
            '/^[ \t]*(【开口前动作】|【台词[ \t]*\/[ \t]*旁白】)[ \t]*$/mu',
            $content,-1,PREG_SPLIT_DELIM_CAPTURE
        );
        $visual=trim((string)array_shift($parts));
        $before='';$dialogue='';
        for($i=0;$i+1<count($parts);$i+=2){
            $heading=trim((string)$parts[$i]);
            $text=trim((string)$parts[$i+1]);
            if($heading===self::BEFORE)$before=$text;
            else $dialogue=$text;
        }
        return [
            'visual'=>$visual,
            'action_before'=>$before,
            'dialogue'=>$dialogue,
        ];
    }
}
