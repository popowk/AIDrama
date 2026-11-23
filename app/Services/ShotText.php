<?php
namespace App\Services;

/**
 * Display original scene copy only once in the shot textarea. The model may
 * also return structured dialogue for voice staging; quoted speech already
 * present in visual should not be appended a second time.
 */
final class ShotText
{
    public const BEFORE='【开口前动作】';
    public const SPEECH='【台词 / 旁白】';

    public static function format(object $shot):string
    {
        $visual=trim((string)($shot->visual??''));
        $parts=[$visual];
        $before=trim((string)($shot->action_before??''));
        $dialogue=trim((string)($shot->dialogue??''));
        if($before!==''&&!self::containsPhrase($visual,$before))
            $parts[]=self::BEFORE."\n".$before;
        // Append only the individual lines NOT already quoted inside visual.
        // For the user's example, all four spoken lines are already present.
        $missing=self::missingDialogueLines($visual,$dialogue);
        if($missing!==[])$parts[]=self::SPEECH."\n".implode("\n",$missing);
        return implode("\n\n",array_filter($parts,static fn($v)=>$v!==''));
    }

    public static function dialogueCoveredByVisual(string $visual,string $dialogue):bool
    {
        return self::missingDialogueLines($visual,$dialogue)===[];
    }

    public static function missingDialogueLines(string $visual,string $dialogue):array
    {
        $lines=preg_split('/\R/u',$dialogue,-1,PREG_SPLIT_NO_EMPTY)?:[];
        $missing=[];
        foreach($lines as $line){
            $line=trim((string)$line);
            if($line==='')continue;
            $speech=(string)preg_replace('/^[^：:\r\n]{1,30}[：:]\s*/u','',$line);
            if(!self::containsPhrase($visual,$speech))$missing[]=$line;
        }
        return $missing;
    }

    private static function containsPhrase(string $haystack,string $needle):bool
    {
        $key=self::normalized($needle);
        return $key!==''&&mb_strpos(self::normalized($haystack),$key)!==false;
    }

    private static function normalized(string $text):string
    {
        // Quotes, commas, ellipses and whitespace vary between AI fields.
        return (string)preg_replace('/[\p{P}\p{S}\p{Z}\s]+/u','',mb_strtolower($text));
    }

    /**
     * Parse only exact full-line headings; arbitrary headings like
     * "监控画面：" are not dialogue fields or speakers.
     * Preserve existing speech metadata on an unlabelled visual-only edit
     * only while all original spoken words are still visibly present.
     */
    public static function parse(string $content,?object $saved=null):array
    {
        $content=str_replace(["\r\n","\r"],"\n",$content);
        $parts=preg_split(
            '/^[ \t]*(【开口前动作】|【台词[ \t]*\/[ \t]*旁白】)[ \t]*$/mu',
            $content,-1,PREG_SPLIT_DELIM_CAPTURE
        );
        $visual=trim((string)array_shift($parts));
        $before='';$dialogue='';
        $hasBefore=false;$hasSpeech=false;
        for($i=0;$i+1<count($parts);$i+=2){
            $heading=trim((string)$parts[$i]);
            $text=trim((string)$parts[$i+1]);
            if($heading===self::BEFORE){$before=$text;$hasBefore=true;}
            else{$dialogue=$text;$hasSpeech=true;}
        }
        if($saved!==null){
            $existingBefore=(string)($saved->action_before??'');
            $existingDialogue=(string)($saved->dialogue??'');
            if(!$hasBefore&&self::containsPhrase($visual,$existingBefore))
                $before=$existingBefore;
            if(!$hasSpeech){
                if(self::dialogueCoveredByVisual($visual,$existingDialogue))
                    $dialogue=$existingDialogue;
            }else{
                // Partial embedded dialogue: only missing lines are shown in
                // the visible section, but the DB keeps full speaker order.
                $dialogue=self::rejoinEmbeddedDialogue($visual,$existingDialogue,$dialogue);
            }
        }
        return ['visual'=>$visual,'action_before'=>$before,'dialogue'=>$dialogue];
    }

    private static function rejoinEmbeddedDialogue(string $visual,string $previous,string $supplement):string
    {
        $original=preg_split('/\R/u',$previous,-1,PREG_SPLIT_NO_EMPTY)?:[];
        $additional=preg_split('/\R/u',$supplement,-1,PREG_SPLIT_NO_EMPTY)?:[];
        if($original===[])return trim($supplement);
        $lines=[];
        foreach($original as $old){
            $spoken=(string)preg_replace('/^[^：:\r\n]{1,30}[：:]\s*/u','',trim($old));
            if(self::containsPhrase($visual,$spoken)){$lines[]=trim($old);continue;}
            if($additional!==[])$lines[]=trim((string)array_shift($additional));
        }
        foreach($additional as $extra)$lines[]=trim((string)$extra);
        return implode("\n",array_filter($lines,static fn($v)=>$v!==''));
    }
}
