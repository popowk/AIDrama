<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
set_exception_handler(static function(\Throwable $e):void {
    fwrite(STDERR,"Media smoke FAILED: ".get_class($e).": ".$e->getMessage()." in ".$e->getFile().":".$e->getLine()."\n");
    exit(1);
});

use App\Http\Controllers\AssetController;
use App\Services\AssetOrigin;
use App\Services\ImageEngine;
use App\Services\PromptBuilder;
use App\Services\PromptPresets;
use App\Services\StoryboardEngine;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function assertMedia(bool $value,string $what):void {
    if(!$value)throw new RuntimeException('Media pipeline smoke: '.$what);
}
$id=DB::table('projects')->insertGetId([
    'name'=>'Creative smoke','script'=>'主持人面对镜头介绍监控。',
    'style'=>'3D国风动画','shot_limit'=>15,
    'status'=>'ready','script_mode'=>'auto','detected_mode'=>'talking',
    'created_at'=>now(),'updated_at'=>now(),
]);
$scene=DB::table('assets')->insertGetId([
    'project_id'=>$id,'type'=>'scene','name'=>'庭院','description'=>'明亮庭院',
    'source_kind'=>'generate','image_status'=>'pending',
    'created_at'=>now(),'updated_at'=>now(),
]);
$brand=DB::table('assets')->insertGetId([
    'project_id'=>$id,'type'=>'prop','name'=>'中维世纪K3C摄像头',
    'description'=>'有特定 Logo 和型号','source_kind'=>'upload','image_status'=>'pending',
    'created_at'=>now(),'updated_at'=>now(),
]);
$shotId=DB::table('shots')->insertGetId([
    'project_id'=>$id,'sequence'=>1,'title'=>'开场',
    'visual'=>'主持人举手介绍','dialogue'=>'监控小助手来啦',
    'action_before'=>'先站稳','on_screen_text'=>'4G监控',
    'delivery'=>'on_camera','duration_seconds'=>8,
    'created_at'=>now(),'updated_at'=>now(),
]);

assertMedia(AssetOrigin::needsUpload('prop','高清摄像头','产品型号参考'),'branded product must require upload');
assertMedia(!AssetOrigin::needsUpload('scene','庭院','普通庭院'),'ordinary scene can generate');

PromptPresets::save('kling','custom','可灵公开参考模版','我自己的可灵镜头指令');
assertMedia(PromptPresets::active('kling')==='我自己的可灵镜头指令','custom preset active');
$project=DB::table('projects')->where('id',$id)->first();
$shot=DB::table('shots')->where('id',$shotId)->first();
$prompt=(new PromptBuilder())->build($project,$shot,'kling');
assertMedia(str_contains($prompt,'我自己的可灵镜头指令'),'Kling prompt custom text');
assertMedia(str_contains($prompt,'屏幕文案：4G监控'),'on-screen copy');
assertMedia(str_contains($prompt,'中维世纪K3C摄像头'),'brand upload warning');
PromptPresets::reset('kling');
assertMedia(PromptPresets::mode('kling')==='reference','reference reset');
DB::table('settings')->where('key','like','prompt.kling.%')->delete();

DB::table('settings')->updateOrInsert(['key'=>'ai.image.delivery'],['value'=>'openai_sync','updated_at'=>now()]);
$controller=new AssetController();
$blocked=$controller->generate($id,$brand,new class extends ImageEngine {
    public function generate(object $p,object $a):array {
        throw new RuntimeException('MUST NOT GENERATE CLIENT PRODUCT');
    }
});
assertMedia($blocked->getStatusCode()===422,'blocked product generation');
$im=(new class extends ImageEngine {
    public function generate(object $project,object $asset):array {
        return [
            'data'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/4p8AAAAASUVORK5CYII='),
            'extension'=>'png','mime'=>'image/png',
        ];
    }
});
$generated=$controller->generate($id,$scene,$im);
assertMedia($generated->getStatusCode()===200,'ordinary scene image generation');
$a=DB::table('assets')->where('id',$scene)->first();
assertMedia($a->image_status==='ready'&&Storage::disk('local')->exists($a->file_path),'private generated image saved');
$file=$controller->file($id,$scene);
assertMedia($file->getStatusCode()===200,'authenticated private file response');

config()->set('app.key','base64:'.base64_encode(str_repeat('x',32)));
app()->forgetInstance('encrypter');
DB::table('settings')->updateOrInsert(['key'=>'ai.text.api_key'],[
    'value'=>Crypt::encryptString('ci-only-test-key'),'updated_at'=>now(),
]);
DB::table('settings')->updateOrInsert(['key'=>'ai.text.model'],[
    'value'=>'ci-fake-model','updated_at'=>now(),
]);
DB::table('settings')->updateOrInsert(['key'=>'ai.text.api_base'],[
    'value'=>'https://api.openai.com/v1','updated_at'=>now(),
]);
// chatgpt2api native 3.x: idempotent submission, remote status polling,
// recoverable completion, and private image storage without synchronous waits.
DB::table('settings')->updateOrInsert(['key'=>'ai.image.delivery'],[
    'value'=>'chatgpt2api_tasks','updated_at'=>now(),
]);
DB::table('settings')->updateOrInsert(['key'=>'ai.image.api_base'],[
    'value'=>'https://api.openai.com/v1','updated_at'=>now(),
]);
DB::table('settings')->updateOrInsert(['key'=>'ai.image.model'],[
    'value'=>'gpt-image-2','updated_at'=>now(),
]);
DB::table('settings')->updateOrInsert(['key'=>'ai.image.api_key'],[
    'value'=>Crypt::encryptString('ci-image-only-test-key'),'updated_at'=>now(),
]);
$asyncScene=DB::table('assets')->insertGetId([
    'project_id'=>$id,'type'=>'scene','name'=>'普通园区','description'=>'广角园区',
    'source_kind'=>'generate','image_status'=>'pending',
    'created_at'=>now(),'updated_at'=>now(),
]);
Http::swap(new \Illuminate\Http\Client\Factory());
$taskSubmits=0;
$remoteStep=0;
$onePixel='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/4p8AAAAASUVORK5CYII=';
Http::fake(function($request) use (&$taskSubmits,&$remoteStep,$onePixel) {
    $url=$request->url();
    if(str_contains($url,'/api/image-tasks/generations')){
        $taskSubmits++;
        assertMedia(str_starts_with($request['client_task_id'],'aidrama-'),'stable client task ID');
        return Http::response(['id'=>$request['client_task_id'],'status'=>'queued'],200);
    }
    if(str_contains($url,'/api/image-tasks?')){
        parse_str((string)parse_url($url,PHP_URL_QUERY),$params);
        $remoteStep++;
        return Http::response(['items'=>[[
            'id'=>$params['ids']??'','status'=>$remoteStep===1?'running':'success',
            'results'=>$remoteStep===1?[]:[['b64_json'=>$onePixel]],
            'actions'=>['resume_poll'=>false],
        ]],'missing_ids'=>[]],200);
    }
    return Http::response(['detail'=>'unexpected request'],500);
});
$queued=$controller->generate($id,$asyncScene,new ImageEngine());
assertMedia($queued->getStatusCode()===202,'async image task submitted quickly');
$task=DB::table('assets')->where('id',$asyncScene)->first();
assertMedia((bool)preg_match('/^aidrama-[a-f0-9]{32}$/D',(string)$task->image_task_id),'persistent remote task id');
$again=$controller->generate($id,$asyncScene,new ImageEngine());
assertMedia($again->getStatusCode()===202&&$taskSubmits===1,'repeat click cannot issue duplicate paid task');
DB::table('assets')->where('id',$asyncScene)->update(['image_poll_after'=>now()->subSeconds(1)]);
$progress=$controller->status($id,$asyncScene)->getData(true);
assertMedia($progress['status']==='generating'&&$progress['stage']==='running','first async poll returns running status');
DB::table('assets')->where('id',$asyncScene)->update(['image_poll_after'=>now()->subSeconds(1)]);
$completed=$controller->status($id,$asyncScene)->getData(true);
assertMedia($completed['status']==='ready','async image completes after polling');
$final=DB::table('assets')->where('id',$asyncScene)->first();
assertMedia(Storage::disk('local')->exists($final->file_path),'async image stored privately');
assertMedia($taskSubmits===1,'polling did not resubmit image generation');
Storage::disk('local')->delete($final->file_path);

// Simulate an edge 524 after the task was accepted upstream. The response is
// uncertain but the request must NOT issue a fresh paid job ID.
$uncertain=DB::table('assets')->insertGetId([
    'project_id'=>$id,'type'=>'scene','name'=>'红砖小院','description'=>'夕阳照耀',
    'source_kind'=>'generate','image_status'=>'pending',
    'created_at'=>now(),'updated_at'=>now(),
]);
Http::swap(new \Illuminate\Http\Client\Factory());
$lostSubmits=0;
Http::fake(function($request) use (&$lostSubmits,$onePixel) {
    if(str_contains($request->url(),'/api/image-tasks/generations')){
        $lostSubmits++;
        return Http::response('Gateway timeout',524);
    }
    if(str_contains($request->url(),'/api/image-tasks?')){
        parse_str((string)parse_url($request->url(),PHP_URL_QUERY),$params);
        return Http::response(['items'=>[[
            'id'=>$params['ids']??'','status'=>'success',
            'results'=>[['b64_json'=>$onePixel]],
            'actions'=>['resume_poll'=>false],
        ]],'missing_ids'=>[]],200);
    }
    return Http::response('unexpected request',500);
});
$ambiguous=$controller->generate($id,$uncertain,new ImageEngine());
assertMedia($ambiguous->getStatusCode()===202,'HTTP 524 is uncertain and recoverable, not final failure');
$unknownTask=DB::table('assets')->where('id',$uncertain)->first();
assertMedia($unknownTask->image_status==='generating'&&$unknownTask->image_task_id!==null,
    '524 retains durable image task ID');
DB::table('assets')->where('id',$uncertain)->update(['image_poll_after'=>now()->subSecond()]);
$recovered=$controller->status($id,$uncertain)->getData(true);
assertMedia($recovered['status']==='ready'&&$lostSubmits===1,'recover 524 via task lookup without duplicate paid submission');
Storage::disk('local')->delete(DB::table('assets')->where('id',$uncertain)->value('file_path'));

// Production chatgpt2api 3.x returns results[0].url, not only b64_json.
// Verify safe same-host file fetch as well as private AIDrama persistence.
$urlScene=DB::table('assets')->insertGetId([
    'project_id'=>$id,'type'=>'scene','name'=>'远景花园','description'=>'清晨花园',
    'source_kind'=>'generate','image_status'=>'pending',
    'created_at'=>now(),'updated_at'=>now(),
]);
$pngBytes=base64_decode($onePixel);
Http::swap(new \Illuminate\Http\Client\Factory());
$downloadHits=0;
Http::fake(function($request) use ($pngBytes,&$downloadHits) {
    if(str_contains($request->url(),'/api/image-tasks/generations'))
        return Http::response(['id'=>$request['client_task_id'],'status'=>'queued'],200);
    if(str_contains($request->url(),'/api/image-tasks?')){
        parse_str((string)parse_url($request->url(),PHP_URL_QUERY),$params);
        return Http::response(['items'=>[[
            'id'=>$params['ids']??'','status'=>'success',
            'results'=>[['url'=>'https://api.openai.com/images/2026/11/16/ci-image.png']],
            'actions'=>['resume_poll'=>false],
        ]],'missing_ids'=>[]],200);
    }
    if($request->url()==='https://api.openai.com/images/2026/11/16/ci-image.png'){
        $downloadHits++;
        return Http::response($pngBytes,200,['Content-Type'=>'image/png']);
    }
    return Http::response('unknown request',500);
});
$controller->generate($id,$urlScene,new ImageEngine());
DB::table('assets')->where('id',$urlScene)->update(['image_poll_after'=>now()->subSecond()]);
$urlResult=$controller->status($id,$urlScene)->getData(true);
if($urlResult['status']!=='ready'||$downloadHits!==1)
    fwrite(STDERR,'URL image result debugging: '.json_encode($urlResult,JSON_UNESCAPED_UNICODE).' downloads='.$downloadHits."\n");
assertMedia($urlResult['status']==='ready'&&$downloadHits===1,'fetch native /images/ provider URL to private image asset');
Storage::disk('local')->delete(DB::table('assets')->where('id',$urlScene)->value('file_path'));

// Hard security boundary: never fetch images from arbitrary remote hosts.
$blockedUrl=false;
try {(new \App\Services\ChatgptImageTasks())->image([
    'results'=>[['url'=>'https://127.0.0.1/files/evil.png']],
]);}catch(\RuntimeException $e){$blockedUrl=true;}
assertMedia($blockedUrl,'private / third-party URL rejected');

$response=[
    'script_mode'=>'talking',
    'shots'=>[[
        'title'=>'口播镜头','visual'=>'对着镜头说话','dialogue'=>'欢迎使用',
        'action_before'=>'先点头','on_screen_text'=>'最新推荐',
        'delivery'=>'on_camera','estimated_seconds'=>9,
    ]],
    'assets'=>[
        ['type'=>'prop','name'=>'K3C太阳能监控摄像头','description'=>'客户产品',
            'source_kind'=>'generate','voice_description'=>''],
        ['type'=>'scene','name'=>'庭院','description'=>'普通院落',
            'source_kind'=>'generate','voice_description'=>''],
    ],
];
Http::swap(new \Illuminate\Http\Client\Factory());
Http::fake(['https://api.openai.com/*'=>Http::response([
    'choices'=>[['message'=>['content'=>json_encode($response,JSON_UNESCAPED_UNICODE)]]],
],200)]);
$engine=new StoryboardEngine;
$engine->setMode('auto');
$result=$engine->generate('主持人在庭院介绍 K3C 监控产品','3D国风动画',15);
assertMedia($result['script_mode']==='talking','AI-detected talking-only mode');
assertMedia($result['shots'][0]['on_screen_text']==='最新推荐','script screen copy');
assertMedia($result['assets'][0]['source_kind']==='upload','override unsafe AI product generation classification');

Storage::disk('local')->delete($a->file_path);
DB::table('projects')->where('id',$id)->delete();
foreach(['ai.text.api_key','ai.text.api_base','ai.text.model','ai.image.api_key','ai.image.api_base','ai.image.model','ai.image.delivery'] as $key)
    DB::table('settings')->where('key',$key)->delete();
echo "Media pipeline, idempotent async images, 524 recovery, private storage and storyboard tests passed\n";
