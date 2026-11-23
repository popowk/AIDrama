<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\StudioController;
use App\Http\Controllers\AssetController;
use App\Services\ProjectSnapshots;
use App\Services\ShotAssetSelection;
use App\Services\PromptBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function assertChoices(bool $ok,string $msg):void
{
    if(!$ok)throw new RuntimeException('Shot asset choices: '.$msg);
}
function reqChoices(array $data=[]):Request
{
    return Request::create('/projects/test','POST',$data,[],[],[
        'HTTP_ACCEPT'=>'application/json',
        'HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest',
    ]);
}
$keys=['ai.text.api_base','ai.text.api_key','ai.text.model'];
$oldSettings=DB::table('settings')->whereIn('key',$keys)->get();
$originalRequest=app('request');
$projectId=DB::table('projects')->insertGetId([
    'name'=>'镜头资产勾选测试','script'=>'悟空在大棚安装摄像头。',
    'status'=>'ready','style'=>'3D国风动画','shot_limit'=>15,
    'video_mode'=>'short','frame_ratio'=>'landscape','script_mode'=>'mixed',
    'created_at'=>now(),'updated_at'=>now(),
]);
try {
    $assetIds=[];
    foreach([
        ['character','悟空',false],
        ['prop','中维AOV太阳能球机',false],
        ['prop','手机',false],
        ['prop','中维世纪jovision品牌标识',false],
        ['prop','旧包装',true],
    ] as [$type,$name,$archived]){
        $assetIds[$name]=DB::table('assets')->insertGetId([
            'project_id'=>$projectId,'type'=>$type,'name'=>$name,
            'description'=>'素材','source_kind'=>$type==='prop'?'upload':'generate',
            'image_status'=>'pending','is_archived'=>$archived,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    $shotId=DB::table('shots')->insertGetId([
        'project_id'=>$projectId,'sequence'=>1,'title'=>'悟空介绍摄像头',
        'visual'=>'悟空举起中维AOV太阳能球机',
        'dialogue'=>'悟空：俺给二师兄介绍监控。',
        'delivery'=>'conversation','duration_seconds'=>8,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $project=DB::table('projects')->where('id',$projectId)->first();
    $shots=DB::table('shots')->where('project_id',$projectId)->get();
    $allAssets=DB::table('assets')->where('project_id',$projectId)->get();
    $auto=ShotAssetSelection::forProject($projectId,$shots,$allAssets);
    assertChoices($auto[(string)$shotId]['mode']==='auto','old shots start with auto matching');
    assertChoices(in_array($assetIds['悟空'],$auto[(string)$shotId]['selected_ids'],true) &&
        in_array($assetIds['中维AOV太阳能球机'],$auto[(string)$shotId]['selected_ids'],true) &&
        !in_array($assetIds['手机'],$auto[(string)$shotId]['selected_ids'],true),
        'old shots have safe initial relevant suggestions');

    $controller=new StudioController();
    $chosen=[$assetIds['悟空'],$assetIds['手机'],$assetIds['旧包装'],99999999];
    $save=$controller->saveShotAssets(reqChoices(['asset_ids'=>$chosen]),$projectId,$shotId);
    $json=json_decode((string)$save->getContent(),true);
    assertChoices($save->getStatusCode()===200 && $json['ok'] &&
        $json['selected_ids']===[$assetIds['悟空'],$assetIds['手机']],
        'manual multi-select keeps only active assets belonging to project');
    $shot=DB::table('shots')->where('id',$shotId)->first();
    assertChoices($shot->asset_selection_mode==='manual','manual choices have durable mode');
    $refs=ShotAssetSelection::references($project,$shot);
    assertChoices(array_column($refs,'name')===['悟空','手机'],
        'video prompt reads explicit manual selection, not automatic candidates');

    $blank=$controller->saveShotAssets(reqChoices(['asset_ids'=>[]]),$projectId,$shotId);
    assertChoices($blank->getStatusCode()===200 &&
        ShotAssetSelection::references($project,
            DB::table('shots')->where('id',$shotId)->first())===[],
        'explicitly unchecking all assets is persisted and does not fall back');
    $builder=new PromptBuilder();
    $freshShot=DB::table('shots')->where('id',$shotId)->first();
    assertChoices(!str_contains($builder->build($project,$freshShot,'kling'),'请提供「悟空」图片'),
        'manually empty selection produces no reference requirement');

    $controller->saveShotAssets(reqChoices([
        'asset_ids'=>[$assetIds['悟空'],$assetIds['中维AOV太阳能球机']]
    ]),$projectId,$shotId);
    $snapshot=ProjectSnapshots::capture($projectId);
    assertChoices($snapshot!==null,'snapshot saves shot selections');
    $controller->saveShotAssets(reqChoices(['asset_ids'=>[]]),$projectId,$shotId);
    $restored=ProjectSnapshots::restore($projectId,$snapshot);
    $shot=DB::table('shots')->where('id',$shotId)->first();
    $restoredRefs=ShotAssetSelection::references($project,$shot);
    assertChoices($restored['shots']===1 && count($restoredRefs)===2 &&
        $shot->asset_selection_mode==='manual',
        'undoable snapshot restore includes checkbox choices and mode');

    app()->instance('request',reqChoices());
    $archived=(new AssetController())->archive($projectId,$assetIds['中维AOV太阳能球机']);
    $body=json_decode((string)$archived->getContent(),true);
    assertChoices($body['archived'] &&
        !in_array('中维AOV太阳能球机',
            array_column($body['shot_references'][(string)$shotId],'name'),true),
        'folded selected asset immediately disappears from per-shot prompt requirements');
    $restore=(new AssetController())->restore($projectId,$assetIds['中维AOV太阳能球机']);
    $body=json_decode((string)$restore->getContent(),true);
    assertChoices(!$body['archived'] &&
        in_array('中维AOV太阳能球机',
            array_column($body['shot_references'][(string)$shotId],'name'),true),
        'restored asset retains manually selected relationship');
    app()->instance('request',$originalRequest);

    foreach([
        'ai.text.api_base'=>'https://api.example.com/v1',
        'ai.text.api_key'=>Crypt::encryptString('ci-only-key'),
        'ai.text.model'=>'ci-text',
    ] as $key=>$value)
        DB::table('settings')->updateOrInsert(['key'=>$key],
            ['value'=>$value,'updated_at'=>now()]);
    $calls=0;
    Http::fake(['https://api.example.com/*'=>function($request)use(&$calls,$assetIds){
        $calls++;
        $system=(string)($request['messages'][0]['content']??'');
        if(str_contains($system,'影视分镜资产匹配员'))
            return Http::response(['choices'=>[[
                'message'=>['content'=>json_encode([
                    'asset_ids'=>[
                        $assetIds['悟空'],$assetIds['中维AOV太阳能球机'],
                        $assetIds['旧包装'],99999999,
                    ],
                ])]
            ]]],200);
        return Http::response(['choices'=>[[
            'message'=>['content'=>'视频里悟空指向一台摄像头，画面自然。']
        ]]],200);
    }]);
    $recognize=$controller->recognizeShotAssets(reqChoices([
        'title'=>'悟空介绍摄像头','visual'=>'悟空在大棚向八戒展示中维AOV太阳能球机',
        'duration_seconds'=>8,
    ]),$projectId,$shotId);
    $data=json_decode((string)$recognize->getContent(),true);
    assertChoices($recognize->getStatusCode()===200 &&
        $data['mode']==='ai' && count($data['selected_ids'])===2 &&
        !in_array($assetIds['旧包装'],$data['selected_ids'],true),
        'actual LLM selects whitelisted active asset IDs only');
    $shot=DB::table('shots')->where('id',$shotId)->first();
    assertChoices($shot->asset_selection_mode==='ai' && $calls===1,
        'explicit re-identify really invoked text model');

    $promptRequest=reqChoices([
        'target'=>'kling','title'=>$shot->title,
        'visual'=>'悟空举起球机，旁边还有手机但无品牌标识。',
        'duration_seconds'=>8,
        'asset_ids_present'=>1,'asset_ids'=>[$assetIds['手机']],
    ]);
    $result=$controller->prompt($projectId,$shotId,$promptRequest,$builder);
    $output=json_decode((string)$result->getContent(),true);
    assertChoices($result->getStatusCode()===200 && $calls===2 &&
        str_contains($output['prompt'],'请提供「手机」图片') &&
        !str_contains($output['prompt'],'请提供「悟空」图片') &&
        !str_contains($output['prompt'],'请提供「中维AOV太阳能球机」图片'),
        'prompt uses current checkbox POST values even before auto-save completes');

    // AI/network/JSON errors must not replace previous manual selections.
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(['https://api.example.com/*'=>Http::response([
        'choices'=>[['message'=>['content'=>'invalid AI JSON']]]
    ],200)]);
    $failed=$controller->recognizeShotAssets(reqChoices(),$projectId,$shotId);
    assertChoices($failed->getStatusCode()===422 &&
        DB::table('shots')->where('id',$shotId)->value('asset_selection_mode')==='ai' &&
        count(ShotAssetSelection::references($project,
            DB::table('shots')->where('id',$shotId)->first()))===2,
        'failed AI recognition keeps existing choices unchanged');
    // Newly generated storyboards receive per-shot asset choices directly
    // from the same storyboard text-model response (no second paid model call).
    $generatedId=DB::table('projects')->insertGetId([
        'name'=>'初次生成 AI 资产预选','script'=>'悟空展示设备。',
        'status'=>'generating','style'=>'3D国风动画',
        'shot_limit'=>15,'script_mode'=>'mixed','video_mode'=>'short',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    try{
        DB::table('generation_jobs')->insert([
            'project_id'=>$generatedId,'kind'=>'shots','status'=>'queued',
            'attempts'=>0,'request_script'=>'悟空在大棚展示设备',
            'request_style'=>'3D国风动画','request_shot_limit'=>15,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $engine=new class extends \App\Services\StoryboardEngine {
            public function generate(string $script,string $style,int $limit):array {
                return [
                    'script_mode'=>'mixed',
                    'shots'=>[
                        ['title'=>'悟空展示产品','visual'=>'悟空向前举起设备',
                            'dialogue'=>'悟空：看好了。','action_before'=>'',
                            'delivery'=>'conversation','duration_seconds'=>7,
                            'on_screen_text'=>'','prompt'=>'模型镜头提示词',
                            'asset_names'=>['悟空','中维AOV太阳能球机']],
                        ['title'=>'空镜头','visual'=>'树影晃动','dialogue'=>'',
                            'action_before'=>'','delivery'=>'none','duration_seconds'=>4,
                            'on_screen_text'=>'','prompt'=>'模型空镜提示词',
                            'asset_names'=>[]],
                    ],
                    'assets'=>[
                        ['type'=>'character','name'=>'悟空','description'=>'人物',
                            'source_kind'=>'generate','image_status'=>'pending',
                            'frame_ratio'=>'inherit','voice_description'=>'男声','prompt'=>'人物参考'],
                        ['type'=>'prop','name'=>'中维AOV太阳能球机','description'=>'产品',
                            'source_kind'=>'upload','image_status'=>'pending',
                            'frame_ratio'=>'inherit','voice_description'=>null,'prompt'=>'设备参考'],
                        ['type'=>'prop','name'=>'无关包装盒','description'=>'未出镜',
                            'source_kind'=>'upload','image_status'=>'pending',
                            'frame_ratio'=>'inherit','voice_description'=>null,'prompt'=>'包装参考'],
                    ],
                ];
            }
        };
        $generation=(new StudioController())->runGeneration($generatedId,$engine);
        $generatedBody=json_decode((string)$generation->getContent(),true);
        assertChoices(($generatedBody['status']??'')==='done',
            'new storyboard generation completed');
        $createdShots=DB::table('shots')->where('project_id',$generatedId)->orderBy('sequence')->get();
        $createdAssets=DB::table('assets')->where('project_id',$generatedId)->get();
        $shotSelections=ShotAssetSelection::forProject($generatedId,$createdShots,$createdAssets);
        assertChoices($createdShots->count()===2 &&
            $shotSelections[(string)$createdShots[0]->id]['mode']==='ai' &&
            $shotSelections[(string)$createdShots[1]->id]['mode']==='ai' &&
            count($shotSelections[(string)$createdShots[0]->id]['selected_ids'])===2 &&
            count($shotSelections[(string)$createdShots[1]->id]['selected_ids'])===0,
            'storyboard AI pre-selects IDs including an intentionally empty shot');
        $chosenNames=$createdAssets->whereIn('id',
            $shotSelections[(string)$createdShots[0]->id]['selected_ids'])->pluck('name')->all();
        assertChoices(in_array('悟空',$chosenNames,true) &&
            in_array('中维AOV太阳能球机',$chosenNames,true) &&
            !in_array('无关包装盒',$chosenNames,true),
            'AI preselection never includes unrelated package assets');
    }finally{
        DB::table('projects')->where('id',$generatedId)->delete();
    }

    echo "Shot asset checkboxes, AI matching, manual zero, fold/restore, snapshots and prompt overrides passed\n";
}finally{
    app()->instance('request',$originalRequest);
    DB::table('projects')->where('id',$projectId)->delete();
    DB::table('settings')->whereIn('key',$keys)->delete();
    foreach($oldSettings as $row)DB::table('settings')->insert((array)$row);
}
