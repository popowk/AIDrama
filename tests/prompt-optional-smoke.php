<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PromptPresets;
use Illuminate\Support\Facades\DB;

$kind='script';
$prefix='prompt.'.$kind.'.';
$backup=DB::table('settings')->whereIn('key',[$prefix.'mode',$prefix.'custom',$prefix.'reference'])->get();
try {
    PromptPresets::save($kind,'custom',PromptPresets::defaultText($kind),'');
    if(PromptPresets::mode($kind)!=='custom' ||
        PromptPresets::custom($kind)!=='' ||
        PromptPresets::active($kind)!=='')
        throw new RuntimeException('Empty custom prompt should be active as an empty string');
    PromptPresets::save($kind,'reference',PromptPresets::defaultText($kind),'');
    if(!str_contains(PromptPresets::active($kind),'分镜'))
        throw new RuntimeException('Reference prompt should still work after blank custom mode');
    echo "Empty custom prompts and reference mode passed\n";
} finally {
    DB::table('settings')->whereIn('key',[$prefix.'mode',$prefix.'custom',$prefix.'reference'])->delete();
    foreach($backup as $entry)
        DB::table('settings')->insert((array)$entry);
}
