<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Match only assets relevant to THIS shot, not the entire active inventory.
 * Matching never claims that image bytes were sent to a downstream model.
 */
final class AssetReferencePicker
{
    public static function list(object $project,object $shot):array
    {
        $assets=DB::table('assets')->where('project_id',$project->id)
            ->orderBy('id')->get(['type','name','description','source_kind','file_path','is_archived']);
        return self::fromAssets($assets,$shot);
    }

    /** Computes all reference previews with just two project-wide queries. */
    public static function forProject(int $projectId):array
    {
        $assets=DB::table('assets')->where('project_id',$projectId)
            ->orderBy('id')->get(['type','name','description','source_kind','file_path','is_archived']);
        $shots=DB::table('shots')->where('project_id',$projectId)
            ->orderBy('sequence')->get(['id','title','visual','dialogue','action_before']);
        $result=[];
        foreach($shots as $shot)
            $result[(string)$shot->id]=self::fromAssets($assets,$shot);
        return $result;
    }

    public static function fromAssets(iterable $assets,object $shot):array
    {
        $content=self::normalized(
            (string)($shot->title??'').' '.(string)($shot->visual??'').' '.
            (string)($shot->dialogue??'').' '.(string)($shot->action_before??'')
        );
        if($content==='')return [];

        $matched=[];
        foreach($assets as $asset){
            if((bool)($asset->is_archived??false))continue;
            $name=trim((string)$asset->name);
            $canonical=self::normalized($name);
            if($canonical==='')continue;
            $score=0;
            if(mb_strpos($content,$canonical)!==false)$score=100;
            else{
                $alias=self::specificAlias($asset->type,$name);
                if($alias!==''&&mb_strpos($content,$alias)!==false)
                    $score=$asset->type==='scene'?65:90;
            }
            if($score===0)continue;
            $matched[]=[
                'name'=>$name,'type'=>$asset->type,
                'direct'=>$score===100,
                'required'=>$score>=90,
                'has_image'=>!empty($asset->file_path),
                'score'=>$score,
            ];
        }
        // Keep all directly named assets, then any strong aliases, and only
        // the most relevant contextual scene hints. A normal shot does not
        // need every prop/character in a 100-asset project.
        usort($matched,static fn($a,$b)=>$b['score']<=>$a['score']);
        $selected=[];$contextual=0;
        foreach($matched as $item){
            if(!$item['required']&&$contextual>=2)continue;
            if(!$item['required'])$contextual++;
            if(count($selected)>=12)break;
            unset($item['score']);
            $selected[]=$item;
        }
        return $selected;
    }

    public static function guidance(object $project,object $shot):string
    {
        $list=self::list($project,$shot);
        if($list===[])return '';
        $lines=['本镜参考资产图片（仅列与本镜相关、当前未折叠的资产）：'];
        foreach($list as $asset){
            $level=$asset['required']?'本镜必需':'可选参考（维持场景连续性）';
            $lines[]='- 请提供「'.$asset['name'].'」图片（'.$level.'）';
        }
        $lines[]='真实品牌或具体型号的外观、包装和标识以所提供的实际参考图为准，不得自行杜撰；此处仅是参考素材要求，不代表图片文件已被发送。';
        return implode("\n",$lines);
    }

    private static function specificAlias(string $type,string $name):string
    {
        if($type==='scene'){
            // 大棚外景 -> 大棚, 夜晚鱼塘场景 -> 夜晚鱼塘.
            $base=preg_replace('/(?:外景|内景|场景|环境|日景|夜景)$/u','',$name);
            $alias=self::normalized((string)$base);
            return mb_strlen($alias)>=2&&$alias!==self::normalized($name)?$alias:'';
        }
        if($type==='character'){
            // A long inventory label such as P01-悟空 also matches 悟空.
            $parts=preg_split('/[·—\-_\s　]+/u',$name,-1,PREG_SPLIT_NO_EMPTY);
            $last=$parts?end($parts):'';
            $alias=self::normalized((string)$last);
            return mb_strlen($alias)>=2&&$alias!==self::normalized($name)?$alias:'';
        }
        if($type==='prop'){
            // An explicit distinct product token remains safe: the common
            // brand word "中维" alone must never match every brand asset.
            $base=preg_replace('/^(?:中维世纪|中维|JOVISION)[-_\s　]*/iu','',$name);
            $base=preg_replace('/产品(?=包装箱|包装盒)/u','',$base);
            $alias=self::normalized((string)$base);
            if(mb_strlen($alias)>=5 && $alias!==self::normalized($name))
                return $alias;
        }
        return '';
    }

    private static function normalized(string $value):string
    {
        return (string)preg_replace('/[\s　_\-·（）()]+/u','',mb_strtolower(trim($value)));
    }
}
