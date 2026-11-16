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
            return self::parseFlattened($lines);
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

    /**
     * ChatGPT/HTML table clipboard fallback: every cell became a line, e.g.
     * 镜号\n画面\n台词\n画面字幕\n1\n场景\n对白\n字幕\n2\n...
     * Rows are bounded by numeric shot identifiers, not by physical lines.
     */
    private static function parseFlattened(array $lines):array
    {
        $lines=array_values(array_filter(
            array_map(static fn($v)=>trim((string)$v),$lines),
            static fn($v)=>$v!==''
        ));
        if(!$lines) throw new RuntimeException('请先粘贴分镜内容');

        $names=[
            '镜号'=>'number','镜头号'=>'number','序号'=>'number',
            '编号'=>'number','镜头'=>'number',
            '画面'=>'visual','画面描述'=>'visual','画面内容'=>'visual','镜头画面'=>'visual','分镜画面'=>'visual',
            '台词'=>'dialogue','口播'=>'dialogue','对白'=>'dialogue','旁白'=>'dialogue','配音'=>'dialogue',
            '画面字幕'=>'on_screen_text','字幕'=>'on_screen_text','画面文案'=>'on_screen_text','屏幕文字'=>'on_screen_text',
            '时长'=>'duration','预计时长'=>'duration','时长秒'=>'duration',
        ];
        $fields=[];$offset=0;
        // Recognize column titles spread over successive lines.
        foreach(array_slice($lines,0,6) as $line) {
            $key=mb_strtolower(preg_replace('/[\s_：:()（）\-]/u','',$line));
            if(!isset($names[$key]))break;
            $fields[]=$names[$key];$offset++;
        }
        if(!in_array('visual',$fields,true) && !in_array('dialogue',$fields,true))
            $offset=0;
        if(!$fields || $offset===0)
            $fields=['number','visual','dialogue','on_screen_text'];

        $rows=[];$currentNumber=null;$body=[];
        $flush=static function()use(&$rows,&$currentNumber,&$body,$fields):void {
            if($currentNumber===null)return;
            if(count($rows)>=40) throw new RuntimeException('单次最多扩写 40 个镜头，请分批粘贴');
            $data=['number'=>$currentNumber,'visual'=>'','dialogue'=>'','on_screen_text'=>'','duration'=>''];
            $contentFields=array_values(array_filter($fields,static fn($key)=>$key!=='number'));
            foreach($body as $i=>$item) {
                $key=$contentFields[$i]??'on_screen_text';
                if(!array_key_exists($key,$data))continue;
                $data[$key].=($data[$key]!==''?"\n":'').$item;
            }
            // If a row had only one nonempty cell it is probably a broken paste.
            if($data['visual']==='' && $data['dialogue']==='' && $data['on_screen_text']==='')
                throw new RuntimeException('镜号 '.$currentNumber.' 后没有画面/台词，请检查粘贴内容');
            $num=(int)$currentNumber;
            $rows[]=[
                'sequence'=>count($rows)+1,
                'original_number'=>(string)$currentNumber,
                'title'=>'镜头 '.str_pad((string)$num,2,'0',STR_PAD_LEFT),
                'visual'=>mb_substr($data['visual'],0,10000),
                'dialogue'=>mb_substr($data['dialogue'],0,10000),
                'on_screen_text'=>mb_substr($data['on_screen_text'],0,5000),
                'duration'=>mb_substr($data['duration'],0,30),
            ];
        };
        foreach(array_slice($lines,$offset) as $line) {
            // Bare line "1", "2", "03" or "镜头 4" starts a new record.
            if(preg_match('/^(?:第\s*)?(?:镜头\s*)?([0-9]{1,3})\s*(?:号|镜)?[.、．]?\s*$/u',$line,$matches)) {
                $flush();$currentNumber=$matches[1];$body=[];continue;
            }
            if($currentNumber===null)continue;
            $body[]=$line;
        }
        $flush();
        if(!$rows)throw new RuntimeException('未识别到镜号。请粘贴“镜号、画面、台词、画面字幕”表格，或从 Excel 直接复制');
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
