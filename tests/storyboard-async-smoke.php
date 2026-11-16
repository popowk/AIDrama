<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$code=\Illuminate\Support\Facades\Artisan::call('migrate',[
    '--path'=>database_path('migrations'),
    '--realpath'=>true,'--force'=>true,'--no-interaction'=>true,
]);
if($code!==0)throw new RuntimeException('In-process Laravel migrations cannot run with --realpath');


use App\Http\Controllers\StudioController;
use App\Services\StoryboardEngine;
use Illuminate\Support\Facades\DB;

$id=DB::table('projects')->insertGetId([
    'name'=>'CI 测试项目','script'=>'打开工厂大门，主角介绍系统。',
    'style'=>'3D国风动画','shot_limit'=>15,'status'=>'generating',
    'created_at'=>now(),'updated_at'=>now(),
]);
$jobs=DB::table('generation_jobs');
$createJob=static function(int $id) use($jobs):int {
    return $jobs->insertGetId([
        'project_id'=>$id,'status'=>'queued','attempts'=>0,
        'request_script'=>'打开工厂大门，主角介绍系统。',
        'request_style'=>'3D国风动画','request_shot_limit'=>15,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
};
$task=$createJob($id);
$engine=new class extends StoryboardEngine {
    public function generate(string $script,string $style,int $limit):array {
        return [
            'shots'=>[[
                'title'=>'工厂大门','visual'=>'工厂远景','dialogue'=>'系统上线',
                'action_before'=>'打开大门','duration_seconds'=>8,'prompt'=>'3D国风动画，工厂',
            ]],
            'assets'=>[[
                'type'=>'scene','name'=>'厂房','description'=>'宽敞厂房',
                'prompt'=>'厂房的场景概念图','voice_description'=>null,
            ]],
        ];
    }
};
$controller=new StudioController();
$response=$controller->runGeneration($id,$engine);
$data=json_decode((string)$response->getContent(),true);
if($data['status']!=='done' ||
   DB::table('generation_jobs')->where('id',$task)->value('status')!=='done' ||
   DB::table('shots')->where('project_id',$id)->count()!==1 ||
   DB::table('assets')->where('project_id',$id)->count()!==1 ||
   DB::table('projects')->where('id',$id)->value('status')!=='ready')
    throw new RuntimeException('Async task success path did not commit');
DB::table('projects')->where('id',$id)->update(['status'=>'generating']);
$failed=$createJob($id);
$engineError=new class extends StoryboardEngine {
    public function generate(string $script,string $style,int $limit):array {
        throw new RuntimeException('AI HTTP 503; please retry');
    }
};
$result=$controller->runGeneration($id,$engineError);
$error=json_decode((string)$result->getContent(),true);
if($error['status']!=='failed' ||
   DB::table('generation_jobs')->where('id',$failed)->value('status')!=='failed' ||
   DB::table('shots')->where('project_id',$id)->count()!==1 ||
   DB::table('projects')->where('id',$id)->value('status')!=='error')
    throw new RuntimeException('Async task failure did not preserve existing shots');
$controller->generationStatus($id);
// Expansion must preserve previous shots and source data and update only script.
DB::table('projects')->where('id',$id)->update([
    'status'=>'generating','input_kind'=>'storyboard',
    'storyboard_source'=>"镜号\t画面\t台词\n1\t大棚门口\t先查看现场",
]);
$expandId=DB::table('generation_jobs')->insertGetId([
    'project_id'=>$id,'kind'=>'expand','status'=>'queued','attempts'=>0,
    'request_script'=>'','request_storyboard'=>"镜号\t画面\t台词\n1\t大棚门口\t先查看现场",
    'request_style'=>'3D国风动画','request_shot_limit'=>15,
    'created_at'=>now(),'updated_at'=>now(),
]);
$expander=new class extends \App\Services\ScriptExpansionEngine {
    public function expand(string $storyboard,string $style,int $limit,string $ratio='landscape',string $preset='custom',mixed $skills=null):array {
        return [['sequence'=>1,'title'=>'镜头 01','visual'=>'大棚门口',
            'dialogue'=>'先查看现场','on_screen_text'=>'',
            'script'=>"画面与动作：镜头推进到大棚门口。\n原始台词/口播：先查看现场"]];
    }
};
$expanded=$controller->runGeneration($id,$engine,$expander);
$expandedResult=json_decode((string)$expanded->getContent(),true);
$p=DB::table('projects')->where('id',$id)->first();
if($expandedResult['status']!=='done' || $expandedResult['kind']!=='expand'
    || DB::table('generation_jobs')->where('id',$expandId)->value('status')!=='done'
    || DB::table('shots')->where('project_id',$id)->count()!==1
    || $p->status!=='ready' || !str_contains($p->script,'先查看现场')
    || count(json_decode($p->script_segments,true))!==1)
    throw new RuntimeException('Storyboard expansion should preserve shots and update the script');
DB::table('projects')->where('id',$id)->delete();
echo "Database-backed generation success/error transitions and old-result preservation passed\n";
