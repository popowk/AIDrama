<?php
namespace App\Services;

/**
 * Conservative, inspectable short-video shot compaction.
 *
 * "Same scene + duration" alone is not enough: a camera-angle change,
 * different character group, explicit transition, or mandatory pre-action can
 * require a real cut. When metadata is absent, explicit visual cut cues are
 * still respected. No external model calls are made by this class.
 */
final class ShotContinuity
{
    public static function canMerge(array $previous,array $current,int $limit):bool
    {
        $scene=trim((string)($current['scene_key']??''));
        if($scene===''||$scene!==trim((string)($previous['scene_key']??'')))return false;
        if(($previous['delivery']??'')!==($current['delivery']??''))return false;

        $a=(int)($previous['duration_seconds']??0);
        $b=(int)($current['duration_seconds']??0);
        if($a<=0||$b<=0||$a+$b>$limit)return false;

        if(!empty($previous['cut_required'])||!empty($current['cut_required']))return false;
        if(trim((string)($current['action_before']??''))!=='')return false;

        foreach([$previous,$current] as $shot){
            if(self::hasCutCue((string)($shot['visual']??'')))return false;
        }

        $oldTime=self::clean((string)($previous['time_key']??''));
        $newTime=self::clean((string)($current['time_key']??''));
        if($oldTime!==''&&$newTime!==''&&$oldTime!==$newTime)return false;

        $oldCamera=self::clean((string)($previous['camera_key']??''));
        $newCamera=self::clean((string)($current['camera_key']??''));
        // If a model specified an angle, require matching angles on both beats.
        if($oldCamera!==$newCamera&&($oldCamera!==''||$newCamera!==''))return false;

        $oldView=self::cameraCue((string)($previous['visual']??''));
        $newView=self::cameraCue((string)($current['visual']??''));
        if($oldView!==$newView&&($oldView!==''||$newView!==''))return false;

        $oldActors=self::actors($previous['characters']??[]);
        $newActors=self::actors($current['characters']??[]);
        if($oldActors!==[]&&$newActors!==[] &&
            count(array_intersect($oldActors,$newActors))===0)return false;

        return true;
    }

    public static function merge(array $previous,array $current):array
    {
        // Up to one second of separate-shot transition time is avoided,
        // but never shorten the actual spoken words below a realistic floor.
        $dialogue=trim((string)($previous['dialogue']??'')."\n".(string)($current['dialogue']??''));
        $characters=preg_replace('/(^|\R)\s*[^：:\r\n]{1,25}[：:]\s*/u','$1',$dialogue);
        $characters=preg_replace('/[\s，。！？!?、；：:“”"‘’]/u','',$characters);
        $speechFloor=(int)ceil(mb_strlen((string)$characters)/3.5);
        $mandatoryLead=trim((string)($previous['action_before']??''))!==''?2:0;
        $total=(int)$previous['duration_seconds']+(int)$current['duration_seconds'];
        $previous['duration_seconds']=max(3,$speechFloor+$mandatoryLead,$total-1);
        $previous['title']=mb_substr(
            (string)$previous['title'].'＋'.(string)$current['title'],0,190
        );
        $previous['visual']=trim((string)$previous['visual'])
            ."\n同一镜头内连续完成：".trim((string)$current['visual']);
        $previous['dialogue']=$dialogue;
        // Only the first beat may have a true pre-dialogue action.
        $previous['action_before']=trim((string)($previous['action_before']??''));
        $previous['on_screen_text']='';
        // Preserve a compact camera/scene group across 3+ candidate beats.
        if(empty($previous['camera_key']))$previous['camera_key']=$current['camera_key']??'';
        if(empty($previous['time_key']))$previous['time_key']=$current['time_key']??'';
        $previous['characters']=array_values(array_unique(array_merge(
            self::actors($previous['characters']??[]),
            self::actors($current['characters']??[])
        )));
        return $previous;
    }

    private static function hasCutCue(string $visual):bool
    {
        return (bool)preg_match(
            '/(?:转场|跳切|切镜|镜头切(?:至|到|换)|画面切(?:至|到|换)|另一个地点|不同机位|隔天|第二天|数小时后|几年后|时空切换)/u',
            $visual
        );
    }

    private static function cameraCue(string $visual):string
    {
        if(preg_match('/(?:航拍|俯拍|鸟瞰|无人机视角)/u',$visual))return 'aerial';
        if(preg_match('/(?:特写|极近景|大近景|微距)/u',$visual))return 'detail';
        if(preg_match('/(?:远景|全景|超广角|建立镜头)/u',$visual))return 'wide';
        if(preg_match('/(?:过肩镜头|过肩视角)/u',$visual))return 'over_shoulder';
        if(preg_match('/(?:中景|半身镜头)/u',$visual))return 'medium';
        return '';
    }

    private static function clean(string $s):string
    {
        return mb_strtolower(trim(preg_replace('/[\s　_-]+/u','',$s)));
    }

    private static function actors(mixed $actors):array
    {
        if(is_string($actors))$actors=preg_split('/[、,，\/|；;\s]+/u',$actors);
        if(!is_array($actors))return [];
        $result=[];
        foreach($actors as $name){
            if(!is_string($name))continue;
            $name=self::clean($name);
            if($name!=='')$result[]=$name;
        }
        return array_values(array_unique($result));
    }
}
