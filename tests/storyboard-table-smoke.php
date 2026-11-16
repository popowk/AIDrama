<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\StoryboardTable;

$markdown="| 镜号 | 画面 | 台词 | 画面字幕 |\n|---|---|---|---|\n| 1 | 大棚外景，八戒发愁 | 八戒：愁得慌！ | 无网无电大棚 |\n| 2 | 悟空拿出设备 | 悟空：瞧俺这法宝！ | 4G监控 |";
$rows=StoryboardTable::parse($markdown);
if(count($rows)!==2 || $rows[0]['dialogue']!=='八戒：愁得慌！' ||
    $rows[1]['on_screen_text']!=='4G监控')throw new RuntimeException('Markdown table failed');
$tsv="镜号\t画面\t台词\t画面字幕\n1\t巡逻大厅\t你好\t巡逻中\n2\t镜头推进\t查看现场\t正在查看";
if(count(StoryboardTable::parse($tsv))!==2)throw new RuntimeException('Excel TSV failed');
$flat="镜号\n画面\n台词\n画面字幕\n1\n大棚外景，八戒站棚边，瞅着旁边马路，一脸担心\n八戒（大棚种植户）：俺老猪这大棚菜长得旺！\n无网无电大棚，选啥安防最实惠？\n2\n悟空背着工具包蹦着出场，工装上有中维世纪jovision的logo，手里亮出中维 AOV 太阳能球机\n悟空（监控安装师傅）：二师兄，瞧俺这法宝！太阳能 4G 监控\n无网无电大棚专用监控";
$flatRows=StoryboardTable::parse($flat);
if(count($flatRows)!==2 || $flatRows[0]['sequence']!==1
    || !str_contains($flatRows[1]['visual'],'AOV 太阳能球机')
    || !str_contains($flatRows[0]['dialogue'],'八戒')
    || $flatRows[1]['on_screen_text']!=='无网无电大棚专用监控')
    throw new RuntimeException('Flattened ChatGPT table paste failed');
$noSubtitle="镜号\n画面\n台词\n画面字幕\n1\n夜景\n开始\n2\n监控画面\n查看";
if(count(StoryboardTable::parse($noSubtitle))!==2 ||
    StoryboardTable::parse($noSubtitle)[1]['dialogue']!=='查看')
    throw new RuntimeException('Flattened table with missing subtitle failed');
$csv="镜号,画面,台词,画面字幕\n1,门口,你好,开始";
if(StoryboardTable::parse($csv)[0]['visual']!=='门口')throw new RuntimeException('CSV failed');
if(!str_contains(StoryboardTable::combine([['sequence'=>1,'title'=>'镜头 01','script'=>'先打开门，再开口。']]),'先打开门'))
    throw new RuntimeException('Script segment merge failed');
try { StoryboardTable::parse('not a table'); throw new RuntimeException('Invalid table accepted'); }
catch(RuntimeException $e) {if($e->getMessage()==='Invalid table accepted')throw $e;}
echo "Storyboard table parsing and script segment merging passed\n";
