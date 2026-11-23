<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\StudioController;
use App\Services\PromptBuilder;
use App\Services\ShotDelivery;
use App\Services\StoryboardEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function assertConversation(bool $ok,string $why):void
{
    if(!$ok)throw new RuntimeException('Conversation delivery: '.$why);
}
assertConversation(ShotDelivery::normalize(
    'on_camera',"悟空：二师兄，看这台。\n八戒：这玩意好使吗？",
    '悟空看向八戒，八戒回头回答他'
)==='conversation','multi-speaker camera-labelled shot is conversational');
assertConversation(ShotDelivery::normalize(
    null,"悟空：二师兄，看这台。\n八戒：这玩意好使吗？",
    '两个人在大棚门口交谈','voiceover'
)==='conversation','infer dialogue when model omitted delivery');
assertConversation(ShotDelivery::normalize(
    'on_camera',"悟空：您好。\n八戒：观众朋友们好。",
    '悟空和八戒面对镜头向观众介绍'
)==='on_camera','explicit direct-to-camera content remains camera speech');
assertConversation(ShotDelivery::normalize(
    'voiceover',"悟空：旁白。\n八戒：旁白。",'远处风景'
)==='voiceover','explicit voiceover is not overridden');
assertConversation(ShotDelivery::normalize(
    'none','', '风吹叶动'
)==='none','silence is preserved');
assertConversation(ShotDelivery::normalize(
    'on_camera','八戒：俺老猪明白了！','八戒转头回应悟空'
)==='conversation','single speaker addressing another person');
assertConversation(str_contains(ShotDelivery::direction('conversation'),'看向对话对象') &&
    str_contains(ShotDelivery::direction('conversation'),'不是摄像机'),
    'conversation prompt directs people to look at each other');
assertConversation(str_contains(
    (string)file_get_contents(dirname(__DIR__).'/resources/views/project.blade.php'),
    'value="conversation"'),'conversation option visible in sound dropdown');

$keys=['ai.text.api_base','ai.text.api_key','ai.text.model'];
$before=DB::table('settings')->whereIn('key',$keys)->get();
$projectId=DB::table('projects')->insertGetId([
    'name'=>'Conversation smoke',
    'style'=>'3D国风动画','script'=>'悟空和八戒聊太阳能监控。',
    'status'=>'ready','shot_limit'=>15,'script_mode'=>'mixed',
    'video_mode'=>'short','frame_ratio'=>'landscape',
    'created_at'=>now(),'updated_at'=>now(),
]);
try {
    $shotId=DB::table('shots')->insertGetId([
        'project_id'=>$projectId,'sequence'=>1,'title'=>'大棚边的对话',
        'visual'=>'悟空朝八戒介绍摄像头',
        'dialogue'=>"悟空：二师兄，瞧这宝贝。\n八戒：有啥用？",
        'delivery'=>'on_camera','duration_seconds'=>9,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $controller=new StudioController();
    $save=Request::create('/projects/'.$projectId.'/shots/'.$shotId,
        'POST',[
            'title'=>'大棚边的对话','visual'=>'悟空看向八戒介绍太阳能监控',
            'dialogue'=>"悟空：二师兄，瞧这宝贝。\n八戒：有啥用？",
            'delivery'=>'conversation','duration_seconds'=>9,
        ],[],[],['HTTP_ACCEPT'=>'application/json']);
    $response=$controller->saveShot($save,$projectId,$shotId);
    assertConversation($response->getStatusCode()===200 &&
        DB::table('shots')->where('id',$shotId)->value('delivery')==='conversation',
        'controller saves selected conversation mode without reload');

    $project=DB::table('projects')->where('id',$projectId)->first();
    $shot=DB::table('shots')->where('id',$shotId)->first();
    $prompt=(new PromptBuilder())->build($project,$shot,'kling');
    assertConversation(str_contains($prompt,'人物之间对话') &&
        str_contains($prompt,'不是摄像机') &&
        str_contains($prompt,'悟空：') &&
        str_contains($prompt,'八戒：') &&
        !str_contains($prompt,'人物面对镜头说话'),
        'generated prompt respects character-to-character gaze and speech');

    $draft=Request::create('/projects/'.$projectId.'/shots/'.$shotId.'/prompt',
        'POST',[
            'target'=>'seedance','title'=>'更新过但尚未保存的镜头',
            'visual'=>'八戒回头看悟空，两人自然对话',
            'dialogue'=>"八戒：真的吗？\n悟空：当然！",
            'delivery'=>'conversation','duration_seconds'=>9,
        ],[],[],['HTTP_ACCEPT'=>'application/json']);
    $fake=new class extends PromptBuilder {
        public function generate(object $project,object $shot,string $target,?array $assetIds=null):string {
            return $this->build($project,$shot,$target,$assetIds);
        }
    };
    $response=$controller->prompt($projectId,$shotId,$draft,$fake);
    $data=json_decode((string)$response->getContent(),true);
    assertConversation($response->getStatusCode()===200 &&
        str_contains($data['prompt'],'八戒：真的吗？') &&
        str_contains($data['prompt'],'不是摄像机') &&
        !str_contains($data['prompt'],'二师兄，瞧这宝贝'),
        'unsaved conversation edits drive adapted video prompt');

    foreach([
        'ai.text.api_base'=>'https://api.example.com/v1',
        'ai.text.api_key'=>Crypt::encryptString('smoke-test'),
        'ai.text.model'=>'fake-model',
    ] as $key=>$value)
        DB::table('settings')->updateOrInsert(['key'=>$key],[
            'value'=>$value,'updated_at'=>now(),
        ]);
    Http::fake(['api.example.com/*'=>Http::response([
        'choices'=>[['message'=>['content'=>json_encode([
            'script_mode'=>'mixed',
            'shots'=>[[
                'title'=>'大棚边两人对话','scene'=>'大棚','visual'=>'悟空和八戒交流',
                'dialogue'=>"悟空：咱装这个。\n八戒：好主意。",
                'delivery'=>'on_camera',
                'estimated_seconds'=>9,
                'action_before'=>'','on_screen_text'=>'',
            ]],
            'assets'=>[],
        ],JSON_UNESCAPED_UNICODE)]]
        ]],200)]);
    $engine=new StoryboardEngine();
    $engine->setVideoMode('short');
    $generated=$engine->generate('悟空与八戒交谈','3D国风动画',15);
    assertConversation(count($generated['shots'])===1 &&
        $generated['shots'][0]['delivery']==='conversation' &&
        str_contains($generated['shots'][0]['dialogue'],'八戒：好主意'),
        'model-provided direct-to-camera tag is normalized for two-person conversation');

    echo "Conversation option, model inference, shot save and prompt preview passed\n";
} finally {
    DB::table('projects')->where('id',$projectId)->delete();
    DB::table('settings')->whereIn('key',$keys)->delete();
    foreach($before as $row)DB::table('settings')->insert((array)$row);
}
