<?php
namespace App\Services;

use RuntimeException;

/**
 * Paste-friendly storyboard importer: Markdown tables, Excel/Sheets TSV,
 * and simple CSV. Original source is kept separately from expanded scripts.
 */
final class StoryboardTable
{
    public static function parse(string $source): array
    {
        $source=trim(str_replace(["\r\n","\r"],"\n",preg_replace('/^\xEF\xBB\xBF/', '', $source)));
        if($source==='') throw new RuntimeException('请先粘贴分镜表格');
        $lines=preg_split('/\n/u',$source)?:[];
        $first='';
        foreach($lines as $line) if(trim($line)!==''){ $first=trim($line); break; }
        $format=str_contains($first,"\t")?'tsv':(str_contains($first,'|')?'markdown':'csv');
        if($format==='csv' && substr_count($first,',')<2)
            throw new RuntimeException('请粘贴带列名的 Markdown 表格、Excel 制表符表格或 CSV 表格');
        $aliases=[
            'number'=>['镜号','镜头号','序号','编号','镜头','shot','shotno','no','#'],
            'visual'=>['画面','画面描述','画面内容','镜头画面','分镜画面','视觉','场景','分镜描述','镜头描述'],
            'dialogue'=>['台词','口播','对白','配音','旁白','解说','配音文案'],
            'on_screen_text'=>['画面字幕','画面文案','字幕','屏幕文案','文案','屏幕文字'],
            'duration'=>['时长','预计时长','秒数','时长秒','duration'],
        ];
        $headers=null;$rows=[];
        foreach($lines as $line) {
            $line=trim($line);
            if($line==='')continue;
            if($format==='tsv')$cells=explode("\t",$line);
            elseif($format==='markdown') {
                $line=preg_replace('/^\s*\||\|\s*$/u','',$line);
                $cells=preg_split('/(?<!\\\\)\|/u',$line)?:[];
            } else $cells=str_getcsv($line);
            $cells=array_map(static fn($c)=>trim(str_replace(['\|','\n'],['|',"\n"],(string)$c)),$cells);
            if(!array_filter($cells,static fn($c)=>$c!==''))continue;
            if(count(array_filter($cells,static fn($c)=>preg_match('/^:?-{3,}:?$/',$c)===1))===count($cells)) continue;
            if($headers===null) {
                $candidate=[];
                foreach($cells as $i=>$cell) {
                    $normalized=mb_strtolower(preg_replace('/[\s_：:()（）\-]/u','',$cell));
                    foreach($aliases as $name=>$names)if(in_array($normalized,$names,true)) {
                        $candidate[$name]=$i;break;
                    }
                }
                if(isset($candidate['visual']) || isset($candidate['dialogue'])) {
                    $headers=$candidate;
                    continue;
                }
                $headers=['number'=>0,'visual'=>1,'dialogue'=>2,'on_screen_text'=>3];
            }
            $get=static fn(string $key)=>isset($headers[$key])?($cells[$headers[$key]]??''):'';
            $visual=$get('visual');$dialogue=$get('dialogue');
            $subtitle=$get('on_screen_text');
            if($visual==='' && $dialogue==='' && $subtitle==='')continue;
            if(count($rows)>=40) throw new RuntimeException('单次最多扩写 40 个镜头，请将较长的分镜表分批导入');
            $rawNumber=$get('number');
            $number=preg_match('/\d+/u',$rawNumber,$match)?(int)$match[0]:count($rows)+1;
            $title='镜头 '.str_pad((string)$number,2,'0',STR_PAD_LEFT);
            $rows[]=[
                'sequence'=>count($rows)+1,'original_number'=>$rawNumber?:((string)$number),
                'title'=>$title,
                'visual'=>mb_substr($visual,0,10000),
                'dialogue'=>mb_substr($dialogue,0,10000),
                'on_screen_text'=>mb_substr($subtitle,0,5000),
                'duration'=>mb_substr($get('duration'),0,30),
            ];
        }
        if(!$rows) throw new RuntimeException('表格未解析出有效分镜，请检查是否有“画面”或“台词”列');
        return $rows;
    }

    public static function combine(array $segments): string
    {
        $parts=[];
        foreach($segments as $segment) {
            $n=(int)($segment['sequence']??count($parts)+1);
            $title=trim((string)($segment['title']??'镜头'));
            $script=trim((string)($segment['script']??''));
            if($script==='')continue;
            $parts[]='【镜头 '.str_pad((string)$n,2,'0',STR_PAD_LEFT).' · '.$title."】\n".$script;
        }
        return implode("\n\n",$parts);
    }
}
