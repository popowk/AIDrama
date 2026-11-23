<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\StudioController;
use App\Services\StoryboardEngine;
use App\Services\PromptBuilder;
use App\Services\PromptPresets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function assertSpeech(bool $result,string $message):void
{
    if(!$result)throw new RuntimeException('Speech regression: '.$message);
}
$keys=['ai.text.api_base','ai.text.api_key','ai.text.model'];
$previous=DB::table('settings')->whereIn('key',$keys)->get();
$projectId=null;
try {
    foreach([
        'ai.text.api_base'=>'https://api.example.com/v1',
        'ai.text.api_key'=>Crypt::encryptString('fake-api-key'),
        'ai.text.model'=>'fake-speech-model',
    ] as $key=>$value)
        DB::table('settings')->updateOrInsert(['key'=>$key],
            ['value'=>$value,'updated_at'=>now()]);

    $spokenSource="悟空：二师兄，摄像头装好了。\n八戒：那夜里也能看清楚吗？";
    $shot=[
        'title'=>'悟空和八戒对话','visual'=>'悟空在大棚门口装好摄像头，看着八戒问候。',
        'dialogue'=>"悟空：二师兄，摄像头装好了。\n八戒：那夜里也能看清楚吗？",
        'action_before'=>'','delivery'=>'conversation',
        'scene'=>'大棚','camera_setup'=>'中景','characters'=>['悟空','八戒'],
        'time_anchor'=>'白天','cut_required'=>false,'estimated_seconds'=>12,
        'asset_names'=>[],
    ];
    $calls=0;
    Http::fake(['https://api.example.com/*'=>function($request)use(&$calls,$shot){
        $calls++;
        $system=(string)($request['messages'][0]['content']??'');
        $user=(string)($request['messages'][1]['content']??'');
        assertSpeech(str_contains($system,'dialogue') &&
            str_contains($system,'分镜页不显示独立的台词') &&
            str_contains($system,'监控画面') &&
            str_contains($user,'逐镜声音/台词要求') &&
            str_contains($user,'冒号字段不是说话人') &&
            str_contains($user,'人物名：说的内容'),
            'system and user prompt require speech even without editor controls');
        return Http::response(['choices'=>[['message'=>[
            'content'=>json_encode([
                'script_mode'=>'mixed','shots'=>[$shot],'assets'=>[],
            ],JSON_UNESCAPED_UNICODE),
        ]]]],200);
    }]);
    $engine=new StoryboardEngine();
    $engine->setVideoMode('short');
    $engine->setMode('mixed');
    $result=$engine->generate($spokenSource,'3D国风动画',15);
    assertSpeech($calls===1&&count($result['shots'])===1 &&
        $result['shots'][0]['delivery']==='conversation' &&
        str_contains($result['shots'][0]['dialogue'],'悟空：') &&
        str_contains($result['shots'][0]['dialogue'],'八戒：') &&
        str_contains($result['shots'][0]['prompt'],'人物台词（按原顺序）'),
        'dialogue and conversational audio remain in normalized storyboard');

    $projectId=DB::table('projects')->insertGetId([
        'name'=>'对白隐藏编辑框回归','script'=>$spokenSource,
        'style'=>'3D国风动画','shot_limit'=>15,'script_mode'=>'mixed',
        'status'=>'ready','video_mode'=>'short',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $shotId=DB::table('shots')->insertGetId([
        'project_id'=>$projectId,'sequence'=>1,'title'=>'原有镜头',
        'visual'=>$shot['visual'],'dialogue'=>$shot['dialogue'],
        'action_before'=>'先摆好相机','delivery'=>'conversation',
        'duration_seconds'=>12,'created_at'=>now(),'updated_at'=>now(),
    ]);
    $response=(new StudioController())->saveShot(Request::create('/','POST',[
        'title'=>'已改画面','visual'=>'悟空在大棚门口调整镜头角度',
        'duration_seconds'=>12,
    ],[],[],['HTTP_ACCEPT'=>'application/json']),$projectId,$shotId);
    $saved=DB::table('shots')->where('id',$shotId)->first();
    assertSpeech($response->getStatusCode()===200 &&
        $saved->dialogue===$shot['dialogue'] &&
        $saved->delivery==='conversation' &&
        $saved->action_before==='先摆好相机',
        'saving simplified editor must preserve hidden speaking fields');
    $builderPrompt=(new PromptBuilder())->build(
        DB::table('projects')->where('id',$projectId)->first(),$saved,'kling');
    assertSpeech(str_contains($builderPrompt,'八戒：那夜里也能看清楚吗？') &&
        str_contains($builderPrompt,'人物之间对话') &&
        str_contains($builderPrompt,'开口前必须完成的动作：先摆好相机'),
        'adapted video brief must retain hidden speech, delivery and required preaction');

    // A source line ending in ":" does not establish a speaker.
    // A model's valid storyboard MUST NOT fail just because a heuristic
    // interpreted a picture/camera/action label as a character's name.
    Http::swap(new \Illuminate\Http\Client\Factory());
    $silent=$shot;$silent['dialogue']='';$silent['delivery']='none';
    $sceneSources=[
        "监控画面：此时画面切换到夜视模式。\n画面呈现：从室外看见大棚里的设备。",
        "画面内容：黑光全彩实时监控。\n屏幕内容：设备正在检测人员。",
        "视频画面：八戒站在棚边看远处。\n镜头画面：太阳能板安装在杆顶。",
        "动作描述：悟空一把拽住师父。\n产品展示：摄像头旋转跟踪。",
        "环境音效：山风吹动树叶。\n拍摄提示：镜头慢慢推近。",
        "监控画面: 枪球联动实时录像。\n画面呈现: 路口车辆经过。",
        "S01：大棚建立镜头\n镜头1：室外无人说话。",
    ];
    foreach($sceneSources as $sceneSource){
        Http::fake(['https://api.example.com/*'=>Http::response([
            'choices'=>[['message'=>['content'=>json_encode([
                'script_mode'=>'mixed','shots'=>[$silent],'assets'=>[],
            ],JSON_UNESCAPED_UNICODE)]]],
        ],200)]);
        $scene=$engine->generate($sceneSource,'3D国风动画',15);
        assertSpeech(count($scene['shots'])===1 &&
            $scene['shots'][0]['dialogue']==='' &&
            $scene['shots'][0]['delivery']==='none',
            'colon-prefixed visual, action, screen and sound fields must not be treated as speakers: '.$sceneSource);
    }

    // Even explicitly labelled dialogue must not be rejected based on a
    // brittle guess. Strong model instructions still request complete speech.
    // The model output is kept so users do not pay again for a failed result.
    Http::swap(new \Illuminate\Http\Client\Factory());
    $missingSecond=$shot;
    $missingSecond['dialogue']='悟空：二师兄，摄像头装好了。';
    Http::fake(['https://api.example.com/*'=>Http::response([
        'choices'=>[['message'=>['content'=>json_encode([
            'script_mode'=>'mixed','shots'=>[$missingSecond],'assets'=>[],
        ],JSON_UNESCAPED_UNICODE)]]],
    ],200)]);
    $partial=$engine->generate($spokenSource,'3D国风动画',15);
    assertSpeech(count($partial['shots'])===1 &&
        str_contains($partial['shots'][0]['dialogue'],'悟空：'),
        'ambiguous source-dialogue quality check must not reject otherwise valid model output');

    // Regression: a screenplay can prefix an action with "悟空一把拽住师父：".
    // That entire action phrase is NOT a character name. The old overly broad
    // pattern falsely claimed this speaker was missing, blocking every retry.
    Http::swap(new \Illuminate\Http\Client\Factory());
    $actionScene=$shot;
    $actionScene['visual']='悟空一把拽住师父，八戒上前帮忙。';
    $actionScene['dialogue']="悟空：师父，您听俺说。\n师父：别拦着我。";
    Http::fake(['https://api.example.com/*'=>Http::response([
        'choices'=>[['message'=>['content'=>json_encode([
            'script_mode'=>'mixed','shots'=>[$actionScene],'assets'=>[],
        ],JSON_UNESCAPED_UNICODE)]]],
    ],200)]);
    $sourceWithAction="悟空一把拽住师父：八戒在旁边看着。\n悟空：师父，您听俺说。\n师父：别拦着我。";
    $actionResult=$engine->generate($sourceWithAction,'3D国风动画',15);
    assertSpeech(count($actionResult['shots'])>=1 &&
        str_contains(implode("\n",array_column($actionResult['shots'],'dialogue')),'师父：别拦着我。'),
        'action prefix is not a missing speaker and valid dialogues are kept');

    Http::swap(new \Illuminate\Http\Client\Factory());
    $actionOnly=$shot;
    $actionOnly['dialogue']='师父：别拦着我。';
    Http::fake(['https://api.example.com/*'=>Http::response([
        'choices'=>[['message'=>['content'=>json_encode([
            'script_mode'=>'mixed','shots'=>[$actionOnly],'assets'=>[],
        ],JSON_UNESCAPED_UNICODE)]]],
    ],200)]);
    $actionOnlyResult=$engine->generate(
        "悟空一把拽住师父：他的衣袖被风吹起。\n悟空拽住：动作特写。\n师父：别拦着我。",
        '3D国风动画',15);
    assertSpeech(count($actionOnlyResult['shots'])===1,
        'both long and short action descriptions are excluded from speaker checks');

    assertSpeech(str_contains(PromptPresets::defaultText('script'),'dialogue') &&
        str_contains(PromptPresets::defaultText('script'),'人物之间') &&
        str_contains(PromptPresets::defaultText('seedance'),'不生成字幕'),
        'new default editable templates match simplified editor and no-subtitle policy');

    echo "Model dialogue requirements retained, visual labels never block and simplified editor preserves voice fields\n";
} finally {
    if($projectId!==null)
        DB::table('projects')->where('id',$projectId)->delete();
    DB::table('settings')->whereIn('key',$keys)->delete();
    foreach($previous as $entry)DB::table('settings')->insert((array)$entry);
}
