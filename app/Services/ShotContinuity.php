<?php
namespace App\Services;

/**
 * Adjacent short-video shots are merged using arithmetic only.
 *
 * Keep the source order, wording and original scene descriptions untouched.
 * The configured 15/30-second limit is a strict upper bound; no camera,
 * setting, character, sound or action heuristics change that decision.
 */
final class ShotContinuity
{
    public static function canMerge(array $previous,array $current,int $limit):bool
    {
        $a=(int)($previous['duration_seconds']??0);
        $b=(int)($current['duration_seconds']??0);
        return $limit>0 && $a>0 && $b>0 && $a+$b<=$limit;
    }

    public static function merge(array $previous,array $current):array
    {
        // Do not invent time savings or alter either duration: 6 + 9 = 15.
        $previous['duration_seconds']=
            (int)$previous['duration_seconds']+(int)$current['duration_seconds'];

        // Preserve each original text verbatim. A newline is only a boundary
        // between two formerly adjacent shots, not a rewritten instruction.
        foreach(['visual','dialogue','action_before','on_screen_text'] as $field){
            $first=(string)($previous[$field]??'');
            $second=(string)($current[$field]??'');
            if($first==='')$previous[$field]=$second;
            elseif($second!=='')$previous[$field]=$first."\n".$second;
        }

        // Keep the leading shot's title unchanged: do not add "＋" or
        // invent transition narration. Preserve relevant reference assets.
        if(is_array($previous['asset_names']??null)||is_array($current['asset_names']??null))
            $previous['asset_names']=array_values(array_unique(array_filter(array_merge(
                is_array($previous['asset_names']??null)?$previous['asset_names']:[],
                is_array($current['asset_names']??null)?$current['asset_names']:[]
            ))));

        // If the first shot is silent and the next one speaks, keep the
        // actual spoken shot's delivery instead of claiming no dialogue.
        if(($previous['delivery']??'none')==='none' &&
            ($current['delivery']??'none')!=='none')
            $previous['delivery']=$current['delivery'];

        return $previous;
    }
}
