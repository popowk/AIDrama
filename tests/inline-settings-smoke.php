<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\StudioController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function inlineSettingsRequest(array $data=[]):Request{
    return Request::create('/settings','POST',$data,[],[],[
        'HTTP_ACCEPT'=>'application/json','HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest',
    ]);
}
function assertSettings(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('Inline model settings: '.$message);
}
$keys=[
    'ai.text.api_base','ai.text.model','ai.text.api_key',
    'prompt.image_character.mode','prompt.image_character.reference','prompt.image_character.custom',
];
$previous=DB::table('settings')->whereIn('key',$keys)->get();
$originalRequest=app('request');
try{
    $controller=new StudioController();
    $request=inlineSettingsRequest([
        'kind'=>'image_character','mode'=>'custom',
        'reference'=>'人物参考基础','custom'=>'人物镜头要求保持一致',
    ]);
    app()->instance('request',$request);
    $result=$controller->savePromptSettings($request);
    $data=json_decode((string)$result->getContent(),true);
    assertSettings($result->getStatusCode()===200 &&
        $data['ok'] && $data['mode']==='custom'&&$data['kind']==='image_character',
        'independent category prompt JSON save');

    $request=inlineSettingsRequest();
    app()->instance('request',$request);
    $result=$controller->resetPromptSettings('image_character');
    $data=json_decode((string)$result->getContent(),true);
    assertSettings($result->getStatusCode()===200 && $data['mode']==='reference' &&
        str_contains($data['reference'],'人物') &&
        DB::table('settings')->where('key','prompt.image_character.custom')->value('value')==='人物镜头要求保持一致',
        'category prompt reset keeps custom text and returns reference content');

    $request=inlineSettingsRequest([
        'kind'=>'text','api_base'=>'https://api.example.com/v1',
        'model'=>'unit-test-text-model','api_key'=>'ci-only-secret',
    ]);
    app()->instance('request',$request);
    $result=$controller->saveSettings($request);
    $data=json_decode((string)$result->getContent(),true);
    $encrypted=DB::table('settings')->where('key','ai.text.api_key')->value('value');
    assertSettings($result->getStatusCode()===200 &&
        $data['ok']&&$data['kind']==='text'&&$data['key_updated']===true &&
        DB::table('settings')->where('key','ai.text.model')->value('value')==='unit-test-text-model' &&
        $encrypted!=='ci-only-secret'&&strlen((string)$encrypted)>30,
        'model saving returns JSON and keeps API key encrypted');

    echo "Model and independent prompt settings JSON saving passed\n";
}finally{
    app()->instance('request',$originalRequest);
    DB::table('settings')->whereIn('key',$keys)->delete();
    foreach($previous as $entry)DB::table('settings')->insert((array)$entry);
}
