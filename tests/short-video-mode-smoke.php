<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\StudioController;
use App\Services\AssetReferencePicker;
use App\Services\PromptBuilder;
use App\Services\StoryboardEngine;
use App\Services\VideoProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function assertShort(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('Short-video mode regression: '.$why);
}
$keys=['ai.text.api_base','ai.text.api_key','ai.text.model'];
$previous=DB::table('settings')->whereIn('key',$keys)->get();
$projectId=DB::table('projects')->insertGetId([
    'name'=>'Short-film mode smoke','style'=>'3D国风动画','script'=>'八戒担心大棚，悟空推荐监控。',
    'shot_limit'=>15,'status'=>'ready','video_mode'=>'short','allow_background_music'=>false,
    'script_mode'=>'mixed','frame_ratio'=>'landscape',
    'created_at'=>now(),'updated_at'=>now(),
]);
$assets=[];
try {
    foreach(['ai.text.api_base'=>'https://api.example.com/v1',
             'ai.text.api_key'=>Crypt::encryptString('ci-fake-key'),
             'ai.text.model'=>'test-text-model'] as $key=>$value)
        DB::table('settings')->updateOrInsert(['key'=>$key],
            ['value'=>$value,'updated_at'=>now()]);
    foreach([
        ['character','悟空',false],
        ['prop','中维AOV太阳能球机',false],
        ['prop','中维AOV产品包装箱',true],
        ['scene','大棚外景',false],
    ] as [$type,$name,$archived]){
        $assets[]=DB::table('assets')->insertGetId([
            'project_id'=>$projectId,'type'=>$type,'name'=>$name,'description'=>'测试资产',
            'source_kind'=>$type==='prop'?'upload':'generate',
            'image_status'=>'pending','is_archived'=>$archived,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    $shots=[
        ['title'=>'八戒担心','scene'=>'大棚','visual'=>'大棚边八戒看马路',
            'dialogue'=>'八戒：俺老猪担心菜被偷。','action_before'=>'皱眉',
            'estimated_seconds'=>4,'on_screen_text'=>'无电大棚选监控','delivery'=>'on_camera'],
        ['title'=>'悟空举起设备','scene'=>'大棚','visual'=>'悟空亮出中维AOV太阳能球机',
            'dialogue'=>'悟空：二师兄，看看这台设备！','action_before'=>'举起手臂',
            'estimated_seconds'=>4,'on_screen_text'=>'产品功能','delivery'=>'on_camera'],
        ['title'=>'远景','scene'=>'公路','visual'=>'路面车辆经过',
            'dialogue'=>'八戒：路上也看得清楚！','action_before'=>'',
            'estimated_seconds'=>4,'on_screen_text'=>'','delivery'=>'on_camera'],
    ];
    $storyboardResponse=[
        'script_mode'=>'mixed','shots'=>$shots,'assets'=>[
            ['type'=>'character','name'=>'悟空','description'=>'安装师傅','source_kind'=>'generate'],
            ['type'=>'prop','name'=>'中维AOV太阳能球机','description'=>'真实产品型号','source_kind'=>'upload'],
        ],
    ];
    $calls=0;
    Http::fake(['https://api.example.com/*'=>function($request)use($storyboardResponse,&$calls){
        $calls++;
        $system=(string)($request['messages'][0]['content']??'');
        if(str_contains($system,'专业视频提示词导演')){
            return Http::response(['choices'=>[[
                'message'=>['content'=>"生成画面：悟空举起设备，八戒回应。\n对白：悟空：好用！\n八戒：真的！"]
            ]]],200);
        }
        return Http::response(['choices'=>[[
            'message'=>['content'=>json_encode($storyboardResponse,JSON_UNESCAPED_UNICODE)]
        ]]],200);
    }]);
    $short=new StoryboardEngine();
    $short->setMode('mixed');
    $short->setVideoMode('short');
    $compact=$short->generate('八戒和悟空在大棚对话。','3D国风动画',15);
    assertShort(count($compact['shots'])===2,'adjacent matching scene shots should merge');
    assertShort($compact['shots'][0]['on_screen_text']==='','short video should not generate subtitles');
    assertShort(str_contains($compact['shots'][0]['dialogue'],'八戒：') &&
        str_contains($compact['shots'][0]['dialogue'],'悟空：') &&
        mb_strpos($compact['shots'][0]['dialogue'],'八戒：')<
          mb_strpos($compact['shots'][0]['dialogue'],'悟空：'),
        'two speakers must remain in original order within one shot');
    assertShort($compact['shots'][0]['duration_seconds']<=15,'merged shot under selected limit');
    assertShort(str_contains($compact['shots'][0]['action_before'],'皱眉')&&
        str_contains($compact['shots'][0]['action_before'],'举起手臂')&&
        $compact['shots'][0]['visual']==="大棚边八戒看马路\n悟空亮出中维AOV太阳能球机",
        'original action and scene text retained without rewriting or invented overlap instructions');

    $story=new StoryboardEngine();
    $story->setMode('mixed');
    $story->setVideoMode('story');
    $normal=$story->generate('正常故事片。','3D国风动画',15);
    assertShort(count($normal['shots'])===3,'legacy story mode must not merge scenes');
    assertShort($normal['shots'][0]['on_screen_text']==='无电大棚选监控',
        'story mode keeps previous scene-copy interpretation');

    $project=DB::table('projects')->where('id',$projectId)->first();
    $shot=(object)[
        'sequence'=>1,'title'=>'测试双人对白',
        'visual'=>'悟空亮出中维AOV太阳能球机，八戒在大棚外景看',
        'action_before'=>'','dialogue'=>"悟空：二师兄，看这台。\n八戒：怎么用？",
        'delivery'=>'on_camera','duration_seconds'=>8,'on_screen_text'=>'不该输出',
    ];
    $references=AssetReferencePicker::guidance($project,$shot);
    assertShort(str_contains($references,'请提供「中维AOV太阳能球机」图片'),
        'brand reference must request named asset without claiming upload');
    assertShort(str_contains($references,'请提供「悟空」图片') &&
        str_contains($references,'请提供「大棚外景」图片'),'other active assets included');
    assertShort(!str_contains($references,'中维AOV产品包装箱'),
        'archived asset must never appear in references');
    assertShort(!str_contains($references,'尚未上传'),'no upload-status nag text');

    $builder=new PromptBuilder();
    $brief=$builder->build($project,$shot,'kling');
    assertShort(str_contains($brief,'不生成、不添加背景音乐') &&
        str_contains($brief,'不要生成字幕'),'BGM and captions default off');
    assertShort(str_contains($brief,'悟空：')&&str_contains($brief,'八戒：'),
        'speaker labels preserved for text model');

    $final=$builder->generate($project,$shot,'kling');
    assertShort($calls===3,'clicking adaption prompt makes a real text-model call');
    assertShort(str_contains($final,'生成画面')&&
        str_contains($final,'请提供「中维AOV太阳能球机」图片') &&
        !str_contains($final,'中维AOV产品包装箱'),
        'LLM-written prompt appends correct active reference requirements');
    assertShort(str_contains($final,'不生成任何字幕') &&
        str_contains($final,'不生成、不添加背景音乐'),
        'provider response cannot omit no-caption/no-BGM requirements');

    $shotId=DB::table('shots')->insertGetId([
        'project_id'=>$projectId,'sequence'=>1,'title'=>$shot->title,
        'visual'=>$shot->visual,'dialogue'=>$shot->dialogue,
        'duration_seconds'=>8,'delivery'=>'on_camera',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $req=Request::create('/projects/'.$projectId.'/shots/'.$shotId.'/prompt',
        'POST',['target'=>'kling'],[],[],['HTTP_ACCEPT'=>'application/json']);
    $controller=new StudioController();
    $response=$controller->prompt($projectId,$shotId,$req,$builder);
    $answer=json_decode((string)$response->getContent(),true);
    assertShort($response->getStatusCode()===200 &&
        $answer['target']==='kling' && str_contains($answer['prompt'],'请提供「悟空」图片'),
        'route controller actually invokes text LLM and JSON-returns result');
    echo "Short video scene compaction, story compatibility, multi-speaker lines, no BGM/captions and active-asset LLM prompts passed\n";
}finally{
    DB::table('projects')->where('id',$projectId)->delete();
    DB::table('settings')->whereIn('key',$keys)->delete();
    foreach($previous as $row)DB::table('settings')->insert((array)$row);
}
