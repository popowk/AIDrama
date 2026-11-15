<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

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
Http::fake(['api.openai.com/*'=>Http::response([
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
foreach(['ai.text.api_key','ai.text.api_base','ai.text.model'] as $key)
    DB::table('settings')->where('key',$key)->delete();
echo "Media pipeline templates, script recognition, product upload block, image storage and video prompt tests passed\n";
