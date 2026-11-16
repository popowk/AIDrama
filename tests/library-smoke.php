<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function(\Throwable $e):void {
    fwrite(STDERR,"Library smoke FAILED: ".get_class($e).": ".$e->getMessage()."\n");
    exit(1);
});

use App\Services\CreativeLibrary;
use App\Services\FrameProfile;
use App\Services\LibraryUpdater;
use App\Services\PromptPresets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function testLibrary(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException('Library test failed: '.$message);
}
$updater=new LibraryUpdater();
$disk=Storage::disk('local');
$files=['library/styles.json','library/skills.json'];
foreach($files as $file)if($disk->exists($file))
    throw new RuntimeException('CI library test may not overwrite preexisting user catalog: '.$file);
try{
    $initialStyles=CreativeLibrary::styles();
    $initialSkills=CreativeLibrary::skills();
    testLibrary(count($initialStyles)>=10&&count($initialSkills)>=6,
        'bundled style & skill catalog is present');

    $input=[
        'version'=>'1.0.1',
        'items'=>[
            'retro_manga'=>[
                'label'=>'复古漫画','category'=>'插画',
                'summary'=>'老式印刷漫画纸与网点', 'prompt'=>'网点灰阶与手绘墨线；复古漫画纸张肌理，主体清晰。',
            ],
        ],
    ];
    $validated=LibraryUpdater::validate('styles',$input);
    testLibrary($validated['version']==='1.0.1','catalog version validation');
    $disk->put('library/styles.json',json_encode($validated,JSON_UNESCAPED_UNICODE));
    testLibrary($updater->current('styles')['version']==='1.0.1','private style library version');
    testLibrary(isset(CreativeLibrary::styles()['retro_manga']),'new independent style is selectable');
    testLibrary(isset(CreativeLibrary::styles()['guofeng_3d']),'original visual style preserved');
    testLibrary(str_contains(CreativeLibrary::stylePrompt('retro_manga'),'网点'),'updated style enters prompt');
    testLibrary($updater->current('skills')['version']==='1.0.0','skill version independent');

    $skill=LibraryUpdater::validate('skills',[
        'version'=>'1.0.2',
        'items'=>['scene_audio'=>[
            'label'=>'场景音效','description'=>'环境声连续性',
            'prompt'=>'确保环境声音与镜头变化一致。',
            'stages'=>['script','video'],
        ]],
    ]);
    $disk->put('library/skills.json',json_encode($skill,JSON_UNESCAPED_UNICODE));
    testLibrary(CreativeLibrary::skillIds(['scene_audio'])===['scene_audio'],
        'new independent skill is selectable');
    testLibrary(str_contains(CreativeLibrary::skillPrompt(['scene_audio'],'video'),'环境声音'),
        'new video skill enters video prompt');
    testLibrary(CreativeLibrary::skillPrompt(['scene_audio'],'image')==='',
        'stage-filtered skill is not injected into image');
    testLibrary(isset(CreativeLibrary::skills()['continuity']),'original skill remains');

    $blocked=false;
    try{LibraryUpdater::validate('styles',[
        'version'=>'1.0.3','items'=>[
            '../../evil'=>['label'=>'bad','category'=>'bad','summary'=>'bad','prompt'=>'bad'],
        ],
    ]);}catch(RuntimeException $e){$blocked=true;}
    testLibrary($blocked,'reject path traversal in catalog IDs');

    $blocked=false;
    try{LibraryUpdater::validate('skills',[
        'version'=>'1.0.3','items'=>[
            'run_script'=>['label'=>'bad','description'=>'bad','prompt'=>'bad',
                'stages'=>['shell']],
        ],
    ]);}catch(RuntimeException $e){$blocked=true;}
    testLibrary($blocked,'reject executable or unknown skill stages');

    $project=(object)[
        'frame_ratio'=>'portrait','style_preset'=>'retro_manga',
        'creative_skills'=>json_encode(['scene_audio']),
    ];
    testLibrary(FrameProfile::effective($project)==='portrait','project portrait default');
    testLibrary(FrameProfile::effective($project,(object)['frame_ratio'=>'landscape'])==='landscape',
        'asset landscape override');
    testLibrary(FrameProfile::requestSize('landscape','gpt-image-2')==='1536x864',
        'gpt-image-2 16:9 exact pixel dimensions');
    testLibrary(FrameProfile::requestSize('portrait','gpt-image-2')==='864x1536',
        'gpt-image-2 9:16 exact pixel dimensions');
    testLibrary(FrameProfile::requestSize('portrait','gpt-image-1')==='1024x1536',
        'older image model uses supported portrait alternative');
    testLibrary(str_contains(CreativeLibrary::projectContext($project,'video'),'环境声音'),
        'new style and skill combine into downstream video prompt');

    // Catalogs must be pure data and NOT overwrite custom user templates.
    testLibrary(str_contains(PromptPresets::defaultText('image'),'项目画风'),'custom prompt system intact');
    echo "Versioned style/skill libraries, independent overlays, safe JSON schema, image ratios and prompt composition passed\n";
}finally{
    foreach($files as $file)if($disk->exists($file))$disk->delete($file);
}
