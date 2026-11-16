<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\AssetController;
use App\Http\Controllers\StudioController;
use App\Services\AssetImagePrompt;
use App\Services\PromptPresets;
use App\Services\PromptBuilder;
use App\Services\StoryboardEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function assertControls(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('Asset controls: '.$why);
}

$kinds=['image_character','image_scene','image_prop'];
$keys=[];
foreach($kinds as $kind)foreach(['mode','reference','custom'] as $field)$keys[]='prompt.'.$kind.'.'.$field;
$previousSettings=DB::table('settings')->whereIn('key',$keys)->get();
$id=DB::table('projects')->insertGetId([
    'name'=>'Asset controls CI','script'=>'院落里的角色拿起道具。',
    'style'=>'3D国风动画','status'=>'ready','shot_limit'=>15,
    'script_mode'=>'mixed','created_at'=>now(),'updated_at'=>now(),
]);
try {
    foreach($kinds as $kind)
        PromptPresets::save($kind,'custom',PromptPresets::defaultText($kind),
            '独立模板-'.$kind);
    $byType=[];
    foreach(['character','scene','prop'] as $type)
        $byType[$type]=DB::table('assets')->insertGetId([
            'project_id'=>$id,'type'=>$type,'name'=>'模型资产-'.$type,
            'description'=>'验证类别路由','prompt'=>'原始生成的描述',
            'source_kind'=>'generate','image_status'=>'pending',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    $project=DB::table('projects')->where('id',$id)->first();
    foreach($byType as $type=>$assetId) {
        $asset=DB::table('assets')->where('id',$assetId)->first();
        $actual=AssetImagePrompt::build($project,$asset);
        assertControls(str_contains($actual,'独立模板-image_'.$type),'correct template for '.$type);
        foreach(['character','scene','prop'] as $other)
            if($other!==$type)assertControls(
                !str_contains($actual,'独立模板-image_'.$other),
                'template must never leak to another category'
            );
    }
    $api=new AssetController();
    $scene=$byType['scene'];
    $save=$api->savePrompt(Request::create('/','POST',
        ['prompt'=>'单独的场景生图提示词，始终不要用角色三视图。']),$id,$scene);
    assertControls($save->getStatusCode()===302,'prompt save redirect');
    $a=DB::table('assets')->where('id',$scene)->first();
    assertControls((bool)$a->prompt_override &&
        AssetImagePrompt::build($project,$a)==='单独的场景生图提示词，始终不要用角色三视图。',
        'asset-level override must take precedence');
    assertControls(str_contains(AssetImagePrompt::build($project,
        DB::table('assets')->where('id',$byType['character'])->first()),
        '独立模板-image_character'),'manual scene change must not affect character');
    $reset=$api->resetPrompt($id,$scene);
    $a=DB::table('assets')->where('id',$scene)->first();
    assertControls($reset->getStatusCode()===302&&!(bool)$a->prompt_override &&
        str_contains(AssetImagePrompt::build($project,$a),'独立模板-image_scene'),
        'reset restores type-specific model setting');

    // Archive keeps files and prompt settings but excludes the asset from
    // active project cards. Restore must retain the same asset ID.
    $archive=$api->archive($id,$byType['character']);
    assertControls($archive->getStatusCode()===302,'archive redirect');
    $stored=DB::table('assets')->where('id',$byType['character'])->first();
    assertControls((bool)$stored->is_archived,'asset archived instead of deleted');
    $view=(new StudioController())->show($id);
    $active=$view->getData()['assets'];
    $hidden=$view->getData()['archivedAssets'];
    assertControls($active->count()===2&&$hidden->count()===1,'separate active and archived lists');
    $restore=$api->restore($id,$byType['character']);
    assertControls($restore->getStatusCode()===302 &&
        DB::table('assets')->where('id',$byType['character'])->value('is_archived')==0,
        'restore keeps original ID');

    // The prior model output may omit a manually archived asset. Its
    // suppression and user settings must survive a full regeneration.
    $api->savePrompt(Request::create('/','POST',['prompt'=>'仅限道具的人工生图词']),$id,$byType['prop']);
    $api->archive($id,$byType['prop']);
    $api->archive($id,$byType['scene']);
    $sceneBefore=DB::table('assets')->where('id',$scene)->first();
    $propBefore=DB::table('assets')->where('id',$byType['prop'])->first();
    $job=DB::table('generation_jobs')->insertGetId([
        'project_id'=>$id,'kind'=>'shots','status'=>'queued','attempts'=>0,
        'request_script'=>'院落里的角色拿起道具。',
        'request_style'=>'3D国风动画','request_shot_limit'=>15,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    DB::table('projects')->where('id',$id)->update(['status'=>'generating']);
    $engine=new class extends StoryboardEngine {
        public function generate(string $script,string $style,int $limit):array {
            return [
                'shots'=>[[
                    'title'=>'镜头','visual'=>'建立场景','dialogue'=>'你好',
                    'action_before'=>'站定','duration_seconds'=>8,'prompt'=>'镜头提示词'
                ]],
                'assets'=>[[
                    'type'=>'scene','name'=>'模型资产-scene',
                    'description'=>'重新生成的场景描述','prompt'=>'重新生成的模型原始词',
                    'voice_description'=>null,'source_kind'=>'generate',
                ]],
            ];
        }
    };
    $result=(new StudioController())->runGeneration($id,$engine);
    $decoded=json_decode((string)$result->getContent(),true);
    assertControls($decoded['status']==='done' &&
        DB::table('generation_jobs')->where('id',$job)->value('status')==='done',
        'regeneration successful');
    $sceneAfter=DB::table('assets')->where('id',$scene)->first();
    $propAfter=DB::table('assets')->where('id',$byType['prop'])->first();
    assertControls($sceneAfter!==null&&$sceneAfter->id===$sceneBefore->id
        &&(bool)$sceneAfter->is_archived,'archived matching asset stays archived');
    assertControls($propAfter!==null&&$propAfter->id===$propBefore->id
        &&(bool)$propAfter->is_archived&& (bool)$propAfter->prompt_override
        &&$propAfter->prompt==='仅限道具的人工生图词',
        'omitted hidden asset preserved with independent prompt and same ID');
    $blocked=$api->generate($id,$byType['prop'],new \App\Services\ImageEngine());
    assertControls($blocked->getStatusCode()===422,
        'archived asset cannot trigger paid image generation');
    echo "Independent type prompts, asset overrides, reversible archive and regeneration preservation passed\n";
}finally {
    DB::table('projects')->where('id',$id)->delete();
    DB::table('settings')->whereIn('key',$keys)->delete();
    foreach($previousSettings as $setting)DB::table('settings')->insert((array)$setting);
}
