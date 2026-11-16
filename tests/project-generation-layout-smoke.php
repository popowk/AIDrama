<?php
declare(strict_types=1);

// Static presentation regression: the final regeneration controls must remain
// below editable script segments, not between paste and segment editing.
$view=(string)file_get_contents(dirname(__DIR__).'/resources/views/project.blade.php');
$markers=[
    'segments' => 'id="script-segments"',
    'save_segments' => '保存全部分段剧本并同步完整剧本',
    'final_actions' => 'class="post-script-generation"',
    'regenerate' => 'id="generate-shots-btn"',
    'generation_state' => 'id="generation-state"',
    'stats' => '<h2>02 / 统计概览</h2>',
];
$positions=[];
foreach($markers as $key=>$marker){
    if(substr_count($view,$marker)!==1)
        throw new RuntimeException('Layout marker must be unique: '.$key);
    $positions[$key]=strpos($view,$marker);
}
$last=-1;
foreach($positions as $name=>$position){
    if($position<=$last)throw new RuntimeException('Incorrect layout order near: '.$name);
    $last=$position;
}
foreach(['route(\'project.generate\'','renderGeneration(info)','generationBox'] as $ref)
    if(!str_contains($view,$ref))
        throw new RuntimeException('Original generation action/status behavior missing: '.$ref);
echo "Storyboard segment editor precedes shot regeneration action and status\n";
