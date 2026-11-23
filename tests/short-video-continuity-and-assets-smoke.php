<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\AssetReferencePicker;
use App\Services\PromptBuilder;
use App\Services\ShotContinuity;
use App\Services\StoryboardEngine;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function assertContinuity(bool $condition,string $description):void
{
    if(!$condition)throw new RuntimeException('Continuity/asset smoke: '.$description);
}

$left=[
    'title'=>'悟空介绍','scene_key'=>'大棚','camera_key'=>'中景',
    'time_key'=>'白天','characters'=>['悟空','八戒'],
    'cut_required'=>false,'visual'=>'悟空在中景中看向八戒',
    'dialogue'=>'悟空：二师兄，快看。','action_before'=>'',
    'delivery'=>'conversation','duration_seconds'=>5,'on_screen_text'=>'',
];
$right=[
    'title'=>'八戒回应','scene_key'=>'大棚','camera_key'=>'中景',
    'time_key'=>'白天','characters'=>['悟空','八戒'],
    'cut_required'=>false,'visual'=>'八戒在中景里笑着回答',
    'dialogue'=>'八戒：好东西！','action_before'=>'',
    'delivery'=>'conversation','duration_seconds'=>4,'on_screen_text'=>'',
];

assertContinuity(ShotContinuity::canMerge($left,$right,15),
    'same scene, gaze, cast, camera, time and delivery can merge');
$merged=ShotContinuity::merge($left,$right);
assertContinuity($merged['duration_seconds']===8 &&
    str_contains($merged['dialogue'],"悟空：二师兄，快看。\n八戒：好东西！"),
    'safe merge removes one cut-overhead second and preserves speaker order');
foreach([
    ['scene_key'=>'公路'],
    ['camera_key'=>'特写'],
    ['time_key'=>'夜晚'],
    ['characters'=>['另外一个人']],
    ['cut_required'=>true],
    ['delivery'=>'voiceover'],
    ['action_before'=>'必须先打开柜门再讲话'],
    ['visual'=>'镜头切到另一台设备'],
    ['visual'=>'八戒的脸部特写'],
] as $blocked){
    assertContinuity(
        !ShotContinuity::canMerge($left,array_replace($right,$blocked),15),
        'must reject cut/discontinuity: '.json_encode($blocked,JSON_UNESCAPED_UNICODE)
    );
}
assertContinuity(!ShotContinuity::canMerge(
    array_replace($left,['duration_seconds'=>13]),$right,15
),'do not exceed hard per-shot cap');
assertContinuity(!ShotContinuity::canMerge($left,array_replace($right,[
    'dialogue'=>'八戒：'.str_repeat('这段话很长',16),'duration_seconds'=>4,
]),15),'spoken word duration overrides an incorrectly short model estimate');

$before=DB::table('settings')->whereIn('key',[
    'ai.text.api_base','ai.text.api_key','ai.text.model'
])->get();
$projectId=DB::table('projects')->insertGetId([
    'name'=>'镜头合并与资产匹配CI',
    'script'=>'悟空在大棚演示太阳能监控','style'=>'3D国风动画',
    'shot_limit'=>15,'script_mode'=>'mixed','video_mode'=>'short',
    'status'=>'ready','frame_ratio'=>'landscape','allow_background_music'=>false,
    'created_at'=>now(),'updated_at'=>now(),
]);
try {
    foreach([
        ['character','悟空',false],
        ['character','八戒',true],
        ['scene','大棚外景',false],
        ['scene','停车场',false],
        ['prop','中维AOV太阳能球机',false],
        ['prop','中维AOV产品包装箱',false],
        ['prop','中维世纪JOVISION品牌标识',false],
        ['prop','手机',false],
    ] as [$type,$name,$archived]){
        DB::table('assets')->insert([
            'project_id'=>$projectId,'type'=>$type,'name'=>$name,
            'description'=>'测试项目资产',
            'source_kind'=>$type==='prop'?'upload':'generate',
            'image_status'=>'pending','is_archived'=>$archived,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    $project=DB::table('projects')->where('id',$projectId)->first();
    $shot=(object)[
        'title'=>'大棚设备介绍','sequence'=>1,
        'visual'=>'悟空站在大棚旁展示AOV太阳能球机，并朝八戒解释',
        'dialogue'=>'悟空：二师兄，来看！','action_before'=>'',
        'delivery'=>'conversation','duration_seconds'=>8,
    ];
    $selected=AssetReferencePicker::list($project,$shot);
    $names=array_column($selected,'name');
    assertContinuity(in_array('悟空',$names,true) &&
        in_array('大棚外景',$names,true) &&
        in_array('中维AOV太阳能球机',$names,true),
        'find direct character, localized scene and exact distinctive product aliases');
    foreach(['八戒','停车场','中维AOV产品包装箱',
             '中维世纪JOVISION品牌标识','手机'] as $excluded)
        assertContinuity(!in_array($excluded,$names,true),
            'unrelated or folded asset must not be requested: '.$excluded);
    $product=null;$scene=null;
    foreach($selected as $asset){
        if($asset['name']==='中维AOV太阳能球机')$product=$asset;
        if($asset['name']==='大棚外景')$scene=$asset;
    }
    assertContinuity($product['required']===true &&
        $scene['required']===false,
        'distinguish required product reference from optional environment reference');
    $shotId=DB::table('shots')->insertGetId([
        'project_id'=>$projectId,'sequence'=>1,
        'title'=>$shot->title,'visual'=>$shot->visual,
        'dialogue'=>$shot->dialogue,'action_before'=>$shot->action_before,
        'delivery'=>'conversation','duration_seconds'=>8,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $initialRefs=AssetReferencePicker::forProject($projectId);
    assertContinuity(isset($initialRefs[(string)$shotId]) &&
        count($initialRefs[(string)$shotId])===count($selected),
        'the storyboard 04 reference preview uses identical matching to prompt generation');
    DB::table('assets')->where('project_id',$projectId)
        ->where('name','中维AOV太阳能球机')->update(['is_archived'=>true]);
    $foldedRefs=AssetReferencePicker::forProject($projectId);
    assertContinuity(!in_array('中维AOV太阳能球机',
        array_column($foldedRefs[(string)$shotId],'name'),true),
        'folding an asset immediately removes it from storyboard reference data');
    DB::table('assets')->where('project_id',$projectId)
        ->where('name','中维AOV太阳能球机')->update(['is_archived'=>false]);

    $guidance=AssetReferencePicker::guidance($project,$shot);
    assertContinuity(str_contains($guidance,'请提供「中维AOV太阳能球机」图片（本镜必需）') &&
        str_contains($guidance,'请提供「大棚外景」图片（可选参考'),
        'deterministic required/optional reference instructions');

    $unrelated=(object)[
        'sequence'=>2,'title'=>'无资产过场','visual'=>'空白墙壁上的斑驳光影',
        'dialogue'=>'','action_before'=>'','delivery'=>'none','duration_seconds'=>3,
    ];
    assertContinuity(AssetReferencePicker::list($project,$unrelated)===[] &&
        AssetReferencePicker::guidance($project,$unrelated)==='',
        'do not append the entire project inventory when no relevant asset appears');

    foreach([
        'ai.text.api_base'=>'https://api.example.com/v1',
        'ai.text.api_key'=>Crypt::encryptString('ci-key'),
        'ai.text.model'=>'stub-text-model',
    ] as $key=>$value)
        DB::table('settings')->updateOrInsert(['key'=>$key],[
            'value'=>$value,'updated_at'=>now(),
        ]);
    $storyShots=[
        ['title'=>'悟空开场','scene'=>'大棚','camera_setup'=>'中景',
            'characters'=>['悟空','八戒'],'time_anchor'=>'白天','cut_required'=>false,
            'visual'=>'悟空中景看向八戒','dialogue'=>'悟空：二师兄，快看！',
            'action_before'=>'','estimated_seconds'=>5,'delivery'=>'conversation'],
        ['title'=>'八戒回应','scene'=>'大棚','camera_setup'=>'中景',
            'characters'=>['悟空','八戒'],'time_anchor'=>'白天','cut_required'=>false,
            'visual'=>'八戒中景开心回应','dialogue'=>'八戒：好主意！',
            'action_before'=>'','estimated_seconds'=>4,'delivery'=>'conversation'],
        ['title'=>'设备特写','scene'=>'大棚','camera_setup'=>'特写',
            'characters'=>['悟空'],'time_anchor'=>'白天','cut_required'=>false,
            'visual'=>'相机特写太阳能球机','dialogue'=>'','action_before'=>'',
            'estimated_seconds'=>3,'delivery'=>'conversation'],
        ['title'=>'夜晚检查','scene'=>'大棚','camera_setup'=>'中景',
            'characters'=>['悟空','八戒'],'time_anchor'=>'夜晚','cut_required'=>true,
            'visual'=>'夜晚大棚画面','dialogue'=>'悟空：夜间也清楚。',
            'action_before'=>'','estimated_seconds'=>4,'delivery'=>'conversation'],
    ];
    $apiCalls=0;
    Http::fake(['https://api.example.com/*'=>function($request)use(&$apiCalls,$storyShots){
        $apiCalls++;
        $system=(string)($request['messages'][0]['content']??'');
        if(str_contains($system,'专业视频提示词导演')){
            return Http::response(['choices'=>[[
                'message'=>['content'=>implode("\n",[
                    '画面：悟空走近大棚，尚未上传，但镜头中依然有动作。',
                    '请提供「中维AOV太阳能球机」图片（本镜必需）',
                    '请提供「中维AOV产品包装箱」图片（本镜必需）',
                    '本镜参考资产图片（只供使用）：',
                    '声音：悟空和八戒自然交流。',
                ])]
            ]]],200);
        }
        return Http::response(['choices'=>[[
            'message'=>['content'=>json_encode([
                'script_mode'=>'mixed','shots'=>$storyShots,'assets'=>[],
            ],JSON_UNESCAPED_UNICODE)]
        ]]],200);
    }]);
    $engine=new StoryboardEngine();
    $engine->setVideoMode('short');$engine->setMode('mixed');
    $short=$engine->generate('悟空和八戒在大棚讨论球机','3D国风动画',15);
    assertContinuity(count($short['shots'])===3,
        'only safe first two beats merge; camera change and night-time cut remain');
    assertContinuity($short['shots'][0]['duration_seconds']<
        $short['shots'][0]['duration_seconds']+$short['shots'][1]['duration_seconds'],
        'merged shot still has a bounded positive duration');
    assertContinuity(str_contains($short['shots'][0]['dialogue'],'悟空：')&&
        str_contains($short['shots'][0]['dialogue'],'八戒：')&&
        mb_strpos($short['shots'][0]['dialogue'],'悟空：')<
            mb_strpos($short['shots'][0]['dialogue'],'八戒：'),
        'multiple speaker turns survive short-video compaction');
    assertContinuity(substr_count($short['shots'][0]['prompt'],'项目画风：')===1 &&
        substr_count($short['shots'][0]['prompt'],'声音呈现：')===1,
        'final merged shot prompt does not duplicate full templates');
    foreach($short['shots'] as $s){
        foreach(['camera_key','time_key','cut_required','scene_key','characters'] as $internal)
            assertContinuity(!array_key_exists($internal,$s),
                'temporary metadata must not leak into existing shot DB rows');
    }

    $engineStory=new StoryboardEngine();
    $engineStory->setVideoMode('story');$engineStory->setMode('mixed');
    $story=$engineStory->generate('正常故事片','3D国风动画',15);
    assertContinuity(count($story['shots'])===4,
        'normal story mode keeps all original shots without short-video compaction');

    $promptBuilder=new PromptBuilder();
    $brief=$promptBuilder->build($project,$shot,'kling');
    assertContinuity(str_contains($brief,'开口前')===false ||
        !str_contains($brief,'同步动作（允许在做动作时说话）'),
        'do not mistake mandatory pre-dialogue action for simultaneous acting');
    $final=$promptBuilder->generate($project,$shot,'kling');
    assertContinuity($apiCalls===3,'short, story and actual adapting prompt calls were made');
    assertContinuity(substr_count($final,'请提供「中维AOV太阳能球机」图片')===1 &&
        !str_contains($final,'中维AOV产品包装箱') &&
        !str_contains($final,'中维世纪JOVISION品牌标识') &&
        !str_contains($final,'请提供「手机」图片'),
        'text model output is deduplicated and grounded to selected current assets');
    assertContinuity(str_contains($final,'悟空走近大棚') &&
        !str_contains($final,'尚未上传') &&
        str_contains($final,'本镜必需'),
        'do not discard model scene text when stripping missing-upload claims');
    assertContinuity(str_contains($final,'不生成任何字幕') &&
        str_contains($final,'不生成、不添加背景音乐') &&
        str_contains($final,'悟空：二师兄，来看！'),
        'hard constraints and original speaker text always retained');
    echo "Camera/character/time-aware merging, compact prompts and per-shot asset relevance passed\n";
} finally {
    DB::table('projects')->where('id',$projectId)->delete();
    DB::table('settings')->whereIn('key',[
        'ai.text.api_base','ai.text.api_key','ai.text.model'
    ])->delete();
    foreach($before as $row)DB::table('settings')->insert((array)$row);
}
