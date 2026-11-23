<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\AssetController;
use App\Http\Controllers\StudioController;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function assertInline(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException('Inline save failure: '.$message);
}
function ajaxRequest(array $data=[]):Request {
    return Request::create('/projects/test','POST',$data,[],[],[
        'HTTP_ACCEPT'=>'application/json',
        'HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest',
    ]);
}

$originalRequest=app('request');
$id=DB::table('projects')->insertGetId([
    'name'=>'Inline edit smoke','script'=>'开场介绍监控。',
    'status'=>'ready','style'=>'3D国风动画','shot_limit'=>15,
    'script_mode'=>'mixed',
    'script_segments'=>json_encode([[
        'sequence'=>1,'title'=>'镜头 01','script'=>'开场画面',
        'visual'=>'厂房','dialogue'=>'你好','on_screen_text'=>'',
    ]],JSON_UNESCAPED_UNICODE),
    'created_at'=>now(),'updated_at'=>now(),
]);
$asset=DB::table('assets')->insertGetId([
    'project_id'=>$id,'type'=>'scene','name'=>'厂房','description'=>'明亮',
    'source_kind'=>'generate','image_status'=>'pending',
    'created_at'=>now(),'updated_at'=>now(),
]);
$shot=DB::table('shots')->insertGetId([
    'project_id'=>$id,'sequence'=>1,'title'=>'原镜头',
    'visual'=>'原画面','dialogue'=>'原台词','duration_seconds'=>8,
    'created_at'=>now(),'updated_at'=>now(),
]);
$uploadedPath=null;$temp=null;
try {
    $api=new AssetController();
    app()->instance('request',ajaxRequest());
    $result=$api->archive($id,$asset);
    $json=json_decode((string)$result->getContent(),true);
    assertInline($result->getStatusCode()===200 &&
        $json['archived']===true &&
        isset($json['shot_references'][(string)$shot]) &&
        DB::table('assets')->where('id',$asset)->value('is_archived')==1,
        'archive must return shot references and persistent folded state');

    $result=$api->restore($id,$asset);
    $json=json_decode((string)$result->getContent(),true);
    assertInline($result->getStatusCode()===200 &&
        $json['archived']===false &&
        isset($json['shot_references'][(string)$shot]) &&
        DB::table('assets')->where('id',$asset)->value('is_archived')==0,
        'restore must return shot references with same asset ID');

    $request=ajaxRequest(['prompt'=>'只属于厂房的图像提示词']);
    app()->instance('request',$request);
    $result=$api->savePrompt($request,$id,$asset);
    $json=json_decode((string)$result->getContent(),true);
    assertInline($result->getStatusCode()===200 &&
        $json['prompt_override']===true &&
        $json['prompt']==='只属于厂房的图像提示词',
        'savePrompt JSON must expose effective prompt');

    $request=ajaxRequest(['frame_ratio'=>'portrait']);
    app()->instance('request',$request);
    $result=$api->frameRatio($request,$id,$asset);
    $json=json_decode((string)$result->getContent(),true);
    assertInline($json['frame_ratio']==='portrait' &&
        $json['prompt_override']===true,
        'ratio save returns JSON and retains custom prompt');

    app()->instance('request',ajaxRequest());
    $result=$api->resetPrompt($id,$asset);
    $json=json_decode((string)$result->getContent(),true);
    assertInline($result->getStatusCode()===200 &&
        $json['prompt_override']===false &&
        DB::table('assets')->where('id',$asset)->value('prompt_override')==0,
        'prompt reset responds with category-generated prompt');

    $request=ajaxRequest(['parts'=>['逐镜新剧本：推门后说话']]);
    $result=(new StudioController())->saveSegments($request,$id);
    $json=json_decode((string)$result->getContent(),true);
    assertInline($result->getStatusCode()===200 &&
        str_contains($json['script'],'逐镜新剧本') &&
        str_contains((string)DB::table('projects')->where('id',$id)->value('script'),'逐镜新剧本'),
        'segment saves must return the merged script without redirect');

    $request=ajaxRequest([
        'title'=>'编辑后的镜头','visual'=>'新的画面',
        'dialogue'=>'更新台词','duration_seconds'=>9,'delivery'=>'voiceover',
    ]);
    $result=(new StudioController())->saveShot($request,$id,$shot);
    $json=json_decode((string)$result->getContent(),true);
    assertInline($result->getStatusCode()===200 &&
        $json['title']==='编辑后的镜头'&&$json['duration_seconds']===9 &&
        is_array($json['references']),
        'shot save should provide heading, duration and asset references for inline update');

    // Test the real authenticated upload flow with a tiny local PNG. No
    // external image service is called and the private file is cleaned up.
    $temp=tempnam(sys_get_temp_dir(),'aidrama-upload-');
    file_put_contents($temp,base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/4p8AAAAASUVORK5CYII='
    ));
    $file=new UploadedFile($temp,'reference.png','image/png',null,true);
    $request=Request::create('/projects/test','POST',[],[],['image'=>$file],[
        'HTTP_ACCEPT'=>'application/json','HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest',
    ]);
    app()->instance('request',$request);
    $result=$api->upload($request,$id,$asset);
    $json=json_decode((string)$result->getContent(),true);
    assertInline($result->getStatusCode()===200 &&
        $json['has_image']===true &&
        !empty($json['thumbnail_url']) && !empty($json['download_url']),
        'upload returns URLs for instant thumbnail update');
    $record=DB::table('assets')->where('id',$asset)->first();
    $uploadedPath=$record->file_path;
    assertInline($record->source_kind==='upload' &&
        Storage::disk('local')->exists($uploadedPath),
        'real reference upload persists privately without browser reload');

    echo "AJAX asset folding, prompt/ratio/upload, segment and shot saves passed\n";
}finally{
    app()->instance('request',$originalRequest);
    if($uploadedPath)Storage::disk('local')->delete($uploadedPath);
    if($temp&&is_file($temp))@unlink($temp);
    DB::table('projects')->where('id',$id)->delete();
}
