<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\StudioController;
use App\Services\PromptBuilder;
use App\Services\ShotText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function assertCombined(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('Combined shot editor: '.$reason);
}
function combinedRequest(array $data):Request {
    return Request::create('/projects/test','POST',$data,[],[],[
        'HTTP_ACCEPT'=>'application/json','HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest'
    ]);
}

$scene='青灰石阶深山古道，悟空回头张望。监控画面：展示远处道路。';
$before='悟空先站稳脚步。';
$words="悟空：师父，走这边！\n唐僧：悟空，慢些。";
$example=(object)['visual'=>$scene,'action_before'=>$before,'dialogue'=>$words];
$combined=ShotText::format($example);
assertCombined(str_contains($combined,$scene) &&
    str_contains($combined,'【开口前动作】') &&
    str_contains($combined,'【台词 / 旁白】') &&
    str_contains($combined,$words),
    'all three sections appear in the same textarea value');
assertCombined(ShotText::parse($combined)===[
    'visual'=>$scene,'action_before'=>$before,'dialogue'=>$words,
], 'a generated shot round-trips without losing scene/action/speaker details');
assertCombined(ShotText::parse("监控画面：有一辆车经过\n画面呈现：路口全景")===[
    'visual'=>"监控画面：有一辆车经过\n画面呈现：路口全景",
    'action_before'=>'','dialogue'=>'',
], 'colon-delimited scene descriptions are not misclassified as dialogue');
assertCombined(ShotText::parse("画面\n\n【台词/旁白】\n悟空：您好。")['dialogue']==='悟空：您好。',
    'editor accepts spacing variation in the dialogue delimiter');

$projectId=DB::table('projects')->insertGetId([
    'name'=>'单框分镜文本回归','style'=>'3D国风动画',
    'script'=>'悟空和唐僧走在山路上。',
    'status'=>'ready','shot_limit'=>15,
    'video_mode'=>'short','script_mode'=>'mixed',
    'created_at'=>now(),'updated_at'=>now(),
]);
try {
    $shotId=DB::table('shots')->insertGetId([
        'project_id'=>$projectId,'sequence'=>1,'title'=>'古道对话',
        'visual'=>$scene,'dialogue'=>$words,'action_before'=>$before,
        'delivery'=>'conversation','duration_seconds'=>8,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $controller=new StudioController();
    $rewritten="悟空举起手电观察古道。\n\n【台词 / 旁白】\n悟空：师父，小心脚下！\n唐僧：我看到了。";
    $response=$controller->saveShot(combinedRequest([
        'title'=>'古道新对话','shot_content'=>$rewritten,'duration_seconds'=>9,
    ]),$projectId,$shotId);
    $payload=json_decode((string)$response->getContent(),true);
    $saved=DB::table('shots')->where('id',$shotId)->first();
    assertCombined($response->getStatusCode()===200 &&
        $saved->visual==='悟空举起手电观察古道。' &&
        $saved->dialogue==="悟空：师父，小心脚下！\n唐僧：我看到了。" &&
        $saved->action_before==='' &&
        $saved->delivery==='conversation' &&
        $payload['shot_content']===ShotText::format($saved),
        'one-box save updates current dialogue, clears deleted pre-action and normalizes content');
    $project=DB::table('projects')->where('id',$projectId)->first();
    $prompt=(new PromptBuilder())->build($project,$saved,'kling');
    assertCombined(substr_count($prompt,'悟空：师父，小心脚下！')===1 &&
        substr_count($prompt,'唐僧：我看到了。')===1 &&
        !str_contains($prompt,'师父，走这边！'),
        'adapted video brief contains edited lines just once, not stale or duplicate dialogue');

    $fake=new class extends PromptBuilder {
        public function generate(object $project,object $shot,string $target,?array $assetIds=null):string {
            return $this->build($project,$shot,$target,$assetIds);
        }
    };
    $draft="悟空在高处眺望村庄。\n\n【台词 / 旁白】\n悟空：八戒，下去看看。";
    $draftRes=$controller->prompt($projectId,$shotId,combinedRequest([
        'title'=>'临时修改','shot_content'=>$draft,
        'duration_seconds'=>6,'target'=>'seedance',
        'asset_ids_present'=>1,
    ]),$fake);
    $draftPayload=json_decode((string)$draftRes->getContent(),true);
    assertCombined($draftRes->getStatusCode()===200 &&
        str_contains($draftPayload['prompt'],'悟空：八戒，下去看看。') &&
        !str_contains($draftPayload['prompt'],'唐僧：我看到了。') &&
        DB::table('shots')->where('id',$shotId)->value('dialogue')===$saved->dialogue,
        'an unsaved combined edit drives prompt generation without changing stored shot');

    $silentRes=$controller->saveShot(combinedRequest([
        'title'=>'空镜','shot_content'=>'监控画面：镜头缓缓扫过公路。',
        'duration_seconds'=>4,
    ]),$projectId,$shotId);
    $silentSaved=DB::table('shots')->where('id',$shotId)->first();
    assertCombined($silentRes->getStatusCode()===200 &&
        $silentSaved->visual==='监控画面：镜头缓缓扫过公路。' &&
        $silentSaved->dialogue==='' &&
        $silentSaved->delivery==='none',
        'removing dialogue from one box results in a genuinely silent shot');

    $legacy=$controller->saveShot(combinedRequest([
        'title'=>'兼容旧接口','visual'=>'老接口画面',
        'dialogue'=>'悟空：旧接口对白。',
        'delivery'=>'on_camera','duration_seconds'=>5,
    ]),$projectId,$shotId);
    assertCombined($legacy->getStatusCode()===200 &&
        DB::table('shots')->where('id',$shotId)->value('dialogue')==='悟空：旧接口对白。',
        'pre-existing separate-field API clients remain supported');
    echo "One visual/action/speech textbox, drafts, save, prompt and legacy API compatibility passed\n";
}finally {
    DB::table('projects')->where('id',$projectId)->delete();
}
