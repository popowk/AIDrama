<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\ShotContinuity;

function assertAdjacent(bool $condition,string $why):void
{
    if(!$condition)throw new RuntimeException('Adjacent shot merge: '.$why);
}
function beat(string $title,int $seconds,string $visual,string $dialogue='',array $extra=[]):array
{
    return array_replace([
        'title'=>$title,'duration_seconds'=>$seconds,'visual'=>$visual,
        'dialogue'=>$dialogue,'action_before'=>'','on_screen_text'=>'',
        'delivery'=>$dialogue===''?'none':'conversation',
        'scene_key'=>'任意场景','camera_key'=>'任意机位',
        'characters'=>[],'time_key'=>'白天','cut_required'=>false,
    ],$extra);
}
function mergeAdjacent(array $shots,int $limit):array
{
    $combined=[];
    foreach($shots as $shot){
        $last=count($combined)-1;
        if($last>=0&&ShotContinuity::canMerge($combined[$last],$shot,$limit))
            $combined[$last]=ShotContinuity::merge($combined[$last],$shot);
        else $combined[]=$shot;
    }
    return $combined;
}
$a=beat('古道呼救',6,"悟空在山道停下。\n监控画面：有人接近。","悟空：师父等等。",[
    'scene_key'=>'古道','camera_key'=>'远景','characters'=>['悟空'],
    'asset_names'=>['悟空'],'on_screen_text'=>'原始文案 A'
]);
$b=beat('师父回应',9,"唐僧骑白马回头。\n画面呈现：树木摇曳。","唐僧：悟空，发生什么事？",[
    'scene_key'=>'不同场景','camera_key'=>'特写','characters'=>['唐僧'],
    'time_key'=>'夜晚','cut_required'=>true,
    'asset_names'=>['唐僧'],'on_screen_text'=>'原始文案 B',
    'action_before'=>'先转头。',
]);
assertAdjacent(ShotContinuity::canMerge($a,$b,15),
    '6+9=15 must merge adjacent shots even when camera, time, actors, delivery or cut flag differ');
$merged=ShotContinuity::merge($a,$b);
assertAdjacent($merged['duration_seconds']===15,'merged duration must be exact sum, no invented 1-second discount');
assertAdjacent($merged['title']==='古道呼救','keep original first shot title without adding + or generated prose');
assertAdjacent($merged['visual']===$a['visual']."\n".$b['visual'] &&
    $merged['dialogue']===$a['dialogue']."\n".$b['dialogue'] &&
    $merged['action_before']===$b['action_before'] &&
    $merged['on_screen_text']==="原始文案 A\n原始文案 B",
    'all original scene, spoken, pre-action and screen-copy text must be preserved verbatim');
assertAdjacent(!str_contains($merged['visual'],'同一镜头内连续完成') &&
    !str_contains($merged['title'],'＋') &&
    $merged['asset_names']===['悟空','唐僧'],
    'no newly invented storyboard copy or lost asset names');
assertAdjacent(!ShotContinuity::canMerge($a,beat('超时',10,'新画面'),15),
    '6+10 exceeds selected 15 seconds');
assertAdjacent(ShotContinuity::canMerge($a,beat('可合并',10,'新画面'),30),
    '6+10 fits selected 30 seconds');
assertAdjacent(!ShotContinuity::canMerge(beat('12',12,'X'),beat('19',19,'Y'),30),
    '12+19 must not exceed 30 seconds');
assertAdjacent(!ShotContinuity::canMerge(beat('invalid',0,'X'),$b,15),
    'invalid zero-length shots must not merge');
$inOrder=[
    beat('一',7,'原文第一句','悟空：第一句。'),
    beat('二',8,'原文第二句','八戒：第二句。'),
    beat('三',10,'原文第三句','唐僧：第三句。'),
    beat('四',20,'原文第四句','悟空：第四句。'),
];
$short=mergeAdjacent($inOrder,15);
assertAdjacent(count($short)===3 && $short[0]['duration_seconds']===15 &&
    $short[1]['duration_seconds']===10 && $short[2]['duration_seconds']===20,
    '15-second greedy grouping preserves original order and rejects overflow');
$long=mergeAdjacent($inOrder,30);
assertAdjacent(count($long)===2 && $long[0]['duration_seconds']===25 &&
    $long[1]['duration_seconds']===20,
    '30-second setting uses the same adjacent-only arithmetic and greedy packing');
assertAdjacent($long[0]['visual']==="原文第一句\n原文第二句\n原文第三句" &&
    $long[0]['dialogue']==="悟空：第一句。\n八戒：第二句。\n唐僧：第三句。",
    'multiple merged shots stay in source sequence with no rewritten text');
echo "Adjacent 15s/30s arithmetic, exact durations and original storyboard text layout passed\n";
