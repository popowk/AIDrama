<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function(\Throwable $e):void{
    fwrite(STDERR,'Model discovery smoke failed: '.$e->getMessage()."\n");
    exit(1);
});

use App\Services\ModelDiscovery;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function verifyModel(bool $ok,string $what):void
{
    if(!$ok)throw new RuntimeException('Model detection regression: '.$what);
}
$service=new ModelDiscovery();
config()->set('app.key','base64:'.base64_encode(str_repeat('z',32)));
app()->forgetInstance('encrypter');
$base='https://api.openai.com/v1';
DB::table('settings')->updateOrInsert(['key'=>'ai.image.api_base'],[
    'value'=>$base,'updated_at'=>now(),
]);
DB::table('settings')->updateOrInsert(['key'=>'ai.image.api_key'],[
    'value'=>Crypt::encryptString('saved-image-key'),'updated_at'=>now(),
]);

// Probe with saved encrypted key, then explicit unsaved key. No model call or
// settings write should happen; only /models is requested.
$seen=[];
Http::fake(function($request)use(&$seen){
    $seen[]=[
        'url'=>$request->url(),
        'authorization'=>$request->header('Authorization')[0]??'',
        'method'=>$request->method(),
    ];
    return Http::response([
        'object'=>'list',
        'data'=>[
            ['id'=>'gpt-4.1'],['id'=>'gpt-image-2'],['id'=>'gpt-image-1'],
            ['id'=>'deepseek-chat'],['id'=>'gemini-3-pro-image'],
        ],
    ],200);
});
$images=$service->discover('image',$base,null);
verifyModel(in_array('gpt-image-2',$images['models'],true),'image model classification');
verifyModel(!in_array('deepseek-chat',$images['models'],true),'image dropdown prioritizes image models');
verifyModel(count($images['all_models'])===5,'all providers remain selectable');
verifyModel($seen[0]['method']==='GET'&&$seen[0]['url']===$base.'/models','standard GET /v1/models request');
verifyModel($seen[0]['authorization']==='Bearer saved-image-key','saved encrypted key used on matching URL');
$text=$service->discover('text',$base,'temporary-unsaved-key');
verifyModel(in_array('deepseek-chat',$text['models'],true)&&
    !in_array('gpt-image-1',$text['models'],true),'text model classification');
verifyModel($seen[1]['authorization']==='Bearer temporary-unsaved-key','temporary key used only for detection');

// The transient key must never overwrite the encrypted stored key.
$encrypted=DB::table('settings')->where('key','ai.image.api_key')->value('value');
verifyModel(Crypt::decryptString($encrypted)==='saved-image-key','detection does not save probe key');

$blocked=false;
try{$service->discover('image','https://attacker.example/v1',null);}
catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'密钥');}
verifyModel($blocked,'saved API key cannot be sent to a changed origin');

$blocked=false;
try{$service->discover('text','http://127.0.0.1/v1','unsafe');}
catch(RuntimeException $e){$blocked=true;}
verifyModel($blocked,'reject private HTTP model probe before request');

$blocked=false;
try{$service->discover('image','https://127.0.0.1/v1','unsafe');}
catch(RuntimeException $e){$blocked=true;}
verifyModel($blocked,'reject loopback HTTPS model probe before request');

echo "Model /v1/models discovery, kind-aware selector, saved-key origin pinning and unsaved-key safety passed\n";
