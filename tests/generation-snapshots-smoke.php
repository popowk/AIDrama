<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\StudioController;
use App\Services\ProjectSnapshots;
use App\Services\StoryboardEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function assertSnapshot(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException('Project snapshots: '.$message);
}
function ajax(array $values=[]):Request
{
    return Request::create('/projects/test','POST',$values,[],[],[
        'HTTP_ACCEPT'=>'application/json',
        'HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest'
    ]);
}
$id=DB::table('projects')->insertGetId([
    'name'=>'历史版本与安全重试', 'script'=>'新稿：悟空走近大棚。',
    'style'=>'3D国风动画','status'=>'generating',
    'shot_limit'=>30,'script_mode'=>'mixed','input_kind'=>'script',
    'created_at'=>now(),'updated_at'=>now(),
]);
$file='projects/'.$id.'/assets/snapshot-ci-'.bin2hex(random_bytes(6)).'.png';
Storage::disk('local')->put($file,base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/4p8AAAAASUVORK5CYII='
));
try {
    $oldShot=DB::table('shots')->insertGetId([
        'project_id'=>$id,'sequence'=>1,'title'=>'旧镜头：悟空问话',
        'visual'=>'悟空与八戒面对面交流','dialogue'=>"悟空：你咋了？\n八戒：担心菜棚。",
        'delivery'=>'conversation','duration_seconds'=>20,
        'prompt'=>'原来保存的分镜提示词',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $oldAsset=DB::table('assets')->insertGetId([
        'project_id'=>$id,'type'=>'character','name'=>'悟空',
        'description'=>'原来的悟空人物设定',
        'source_kind'=>'generate','image_status'=>'ready','file_path'=>$file,
        'prompt'=>'人工修改的角色提示词','prompt_override'=>true,
        'frame_ratio'=>'portrait','is_archived'=>false,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $archived=DB::table('assets')->insertGetId([
        'project_id'=>$id,'type'=>'prop','name'=>'旧道具',
        'description'=>'已折叠，不应在重新生成时重新出现',
        'source_kind'=>'generate','image_status'=>'pending',
        'prompt'=>'特别的道具提示词','prompt_override'=>true,'is_archived'=>true,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $job=DB::table('generation_jobs')->insertGetId([
        'project_id'=>$id,'kind'=>'shots','status'=>'queued','attempts'=>0,
        'request_script'=>'模型接收的新剧本',
        'request_style'=>'3D国风动画','request_shot_limit'=>30,
        'request_video_mode'=>'short',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $engine=new class extends StoryboardEngine {
        public function generate(string $script,string $style,int $limit):array {
            return [
                'shots'=>[[
                    'title'=>'全新分镜','visual'=>'悟空在菜棚里观察',
                    'dialogue'=>'悟空：办法有了。','action_before'=>'',
                    'delivery'=>'conversation','duration_seconds'=>8,
                    'on_screen_text'=>'','prompt'=>'AI 新镜头提示词',
                ]],
                'assets'=>[[
                    'type'=>'character','name'=>'孙悟空（重命名）',
                    'description'=>'新版人物','prompt'=>'新版 AI 提示词',
                    'source_kind'=>'generate','image_status'=>'pending',
                    'voice_description'=>null,'frame_ratio'=>'inherit',
                ]],
            ];
        }
    };
    $controller=new StudioController();
    $ok=$controller->runGeneration($id,$engine);
    $result=json_decode((string)$ok->getContent(),true);
    assertSnapshot($result['status']==='done','regeneration should succeed');
    $versions=ProjectSnapshots::list($id);
    assertSnapshot($versions->count()===1 &&
        $versions[0]->reason==='before_regeneration' &&
        (int)$versions[0]->shot_count===1 &&(int)$versions[0]->asset_count===2,
        'old shots and both assets captured atomically before replacing them');
    $snapshotId=(int)$versions[0]->id;
    assertSnapshot(DB::table('assets')->where('project_id',$id)->where('name','悟空')->count()===0,
        'renamed generated asset is not silently mistaken for an old asset');

    // The user may have edited their source script after generation. A version
    // restore must NEVER overwrite those edits with an earlier source draft.
    DB::table('projects')->where('id',$id)->update([
        'script'=>'用户刚刚修改的新剧本原稿','status'=>'ready','updated_at'=>now(),
    ]);
    $restore=$controller->restoreSnapshot(ajax(),$id,$snapshotId);
    $body=json_decode((string)$restore->getContent(),true);
    assertSnapshot($restore->getStatusCode()===200&&$body['ok'] &&
        $body['assets']===2 && $body['shots']===1 &&
        $body['missing_images']===0 && $body['safety_snapshot_id']!==null,
        'restoring old version creates a new current-version backup');
    assertSnapshot(DB::table('shots')->where('id',$oldShot)->value('prompt')===
        '原来保存的分镜提示词','old shot ID and handwritten prompt restored');
    $asset=DB::table('assets')->where('id',$oldAsset)->first();
    assertSnapshot($asset&&$asset->file_path===$file && (bool)$asset->prompt_override &&
        $asset->prompt==='人工修改的角色提示词' &&
        $asset->frame_ratio==='portrait',
        'old image path and user prompt/ratio restored exactly');
    assertSnapshot(Storage::disk('local')->exists($file),
        'restore must never delete original private image files');
    assertSnapshot(DB::table('assets')->where('id',$archived)->value('is_archived')==1,
        'archived state preserved');
    assertSnapshot(DB::table('projects')->where('id',$id)->value('script')===
        '用户刚刚修改的新剧本原稿','current source script remains intact');
    assertSnapshot(DB::table('projects')->where('id',$id)->value('shot_limit')==30,
        'history keeps the 30-second maximum used by its older shots');

    $backups=ProjectSnapshots::list($id);
    $beforeRestore=$backups->firstWhere('reason','before_restore');
    assertSnapshot($beforeRestore!==null,'safety snapshot created before rollback');
    $undo=$controller->restoreSnapshot(ajax(),$id,(int)$beforeRestore->id);
    $undoBody=json_decode((string)$undo->getContent(),true);
    assertSnapshot($undoBody['ok']&&
        DB::table('shots')->where('project_id',$id)->value('title')==='全新分镜',
        'restore can itself be undone using a safely captured version');

    // JSON saving errors must be actionable rather than a 302 HTML redirect.
    DB::table('shots')->where('project_id',$id)->update(['duration_seconds'=>20]);
    $limitChange=ajax([
        'name'=>'历史版本与安全重试','script'=>'现在原稿','style'=>'3D国风动画',
        'style_preset'=>'custom','shot_limit'=>15,
    ]);
    $bad=$controller->update($limitChange,$id);
    assertSnapshot($bad->getStatusCode()===422 &&
        isset(json_decode((string)$bad->getContent(),true)['errors']['shot_limit']),
        'invalid shot-duration change returns structured JSON error');

    DB::table('projects')->where('id',$id)->update(['status'=>'generating']);
    $shotId=(int)DB::table('shots')->where('project_id',$id)->value('id');
    $denied=$controller->saveShot(ajax([
        'title'=>'不应写入','visual'=>'禁止并发覆盖','duration_seconds'=>8,
    ]),$id,$shotId);
    assertSnapshot($denied->getStatusCode()===409 &&
        DB::table('shots')->where('id',$shotId)->value('title')==='全新分镜',
        'editing a shot during generation must not overwrite model output');

    // Failed task can be retried without new source changes or duplicate jobs.
    $failed=DB::table('generation_jobs')->insertGetId([
        'project_id'=>$id,'kind'=>'expand','status'=>'failed','attempts'=>1,
        'request_script'=>'旧任务里的原稿','request_storyboard'=>"镜号\t画面\n1\t旧画面",
        'request_style'=>'3D国风动画','request_shot_limit'=>15,
        'request_video_mode'=>'story','request_background_music'=>false,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $retry=$controller->retryGeneration(ajax(),$id);
    $retried=json_decode((string)$retry->getContent(),true);
    assertSnapshot($retried['ok']&&$retried['status']==='queued',
        'failed job should be queued again');
    $newJob=DB::table('generation_jobs')->where('id',$retried['job_id'])->first();
    assertSnapshot($newJob->request_script==='旧任务里的原稿' &&
        $newJob->request_storyboard==="镜号\t画面\n1\t旧画面" &&
        $newJob->request_video_mode==='story' &&
        $newJob->kind==='expand' &&
        $newJob->attempts===0,
        'retry must preserve original job parameters and original job record');
    $count=DB::table('generation_jobs')->where('project_id',$id)->count();
    $again=$controller->retryGeneration(ajax(),$id);
    assertSnapshot($again->getStatusCode()===200&&
        DB::table('generation_jobs')->where('project_id',$id)->count()===$count,
        'double-clicking retry must not create a duplicate paid job');
    $blocked=$controller->restoreSnapshot(ajax(),$id,$snapshotId);
    assertSnapshot($blocked->getStatusCode()===409,
        'snapshot restore blocked while generated job is queued');

    DB::table('generation_jobs')->where('id',$newJob->id)->update(['status'=>'failed']);
    DB::table('projects')->where('id',$id)->update(['status'=>'ready']);
    DB::table('assets')->where('project_id',$id)->limit(1)->update([
        'image_status'=>'generating',
    ]);
    $blockedImage=$controller->restoreSnapshot(ajax(),$id,$snapshotId);
    assertSnapshot($blockedImage->getStatusCode()===409,
        'image tasks must not be lost by restoring a snapshot');

    echo "Atomic snapshots, renamed-asset recovery, reversible restore, JSON errors and deduplicated job retries passed\n";
}finally {
    DB::table('projects')->where('id',$id)->delete();
    Storage::disk('local')->delete($file);
}
