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
            str_contains($user,'逐镜声音/台词要求') &&
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

    Http::swap(new \Illuminate\Http\Client\Factory());
    $silent=$shot;$silent['dialogue']='';$silent['delivery']='none';
    Http::fake(['https://api.example.com/*'=>Http::response([
        'choices'=>[['message'=>['content'=>json_encode([
            'script_mode'=>'mixed','shots'=>[$silent],'assets'=>[],
        ],JSON_UNESCAPED_UNICODE)]]],
    ],200)]);
    try{
        $engine->generate($spokenSource,'3D国风动画',15);
        throw new RuntimeException('Silent storyboard was incorrectly accepted');
    }catch(RuntimeException $e){
        assertSpeech(str_contains($e->getMessage(),'未生成任何口播/台词'),
            'reject silent output when source explicitly contains spoken dialogue');
    }

    Http::swap(new \Illuminate\Http\Client\Factory());
    $missingSecond=$shot;$missingSecond['dialogue']='悟空：二师兄，摄像头装好了。';
    Http::fake(['https://api.example.com/*'=>Http::response([
        'choices'=>[['message'=>['content'=>json_encode([
            'script_mode'=>'mixed','shots'=>[$missingSecond],'assets'=>[],
        ],JSON_UNESCAPED_UNICODE)]]],
    ],200)]);
    try{
        $engine->generate($spokenSource,'3D国风动画',15);
        throw new RuntimeException('Missing second speaker was incorrectly accepted');
    }catch(RuntimeException $e){
        assertSpeech(str_contains($e->getMessage(),'八戒'),
            'reject a missing explicitly named speaker rather than saving incomplete result');
    }

    // S01/S02 and 镜头1 are shot IDs, not people speaking; a genuine
    // silent establishing shot must still be accepted.
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(['https://api.example.com/*'=>Http::response([
        'choices'=>[['message'=>['content'=>json_encode([
            'script_mode'=>'mixed','shots'=>[$silent],'assets'=>[],
        ],JSON_UNESCAPED_UNICODE)]]],
    ],200)]);
    $silentScene=$engine->generate("S01：大棚环境建立镜头\n镜头1：白天全景没有对白",
        '3D国风动画',15);
    assertSpeech(count($silentScene['shots'])===1 &&
        $silentScene['shots'][0]['dialogue']==='',
        'numbered storyboard metadata must not be misread as named speakers');

    assertSpeech(str_contains(PromptPresets::defaultText('script'),'dialogue') &&
        str_contains(PromptPresets::defaultText('script'),'人物之间') &&
        str_contains(PromptPresets::defaultText('seedance'),'不生成字幕'),
        'new default editable templates match simplified editor and no-subtitle policy');

    echo "Original speakers survive simplified editing, system prompts, video adaptation and missing-speaker checks\n";
} finally {
    if($projectId!==null)
        DB::table('projects')->where('id',$projectId)->delete();
    DB::table('settings')->whereIn('key',$keys)->delete();
    foreach($previous as $entry)DB::table('settings')->insert((array)$entry);
}
