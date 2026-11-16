<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Safe text-only reference requests. Asset text is data, never actual attachments. */
final class AssetReferencePicker
{
    public static function list(object $project,object $shot):array
    {
        $assets=DB::table('assets')->where('project_id',$project->id)
            ->where('is_archived',false)->orderBy('id')->get(['type','name','description','source_kind','file_path']);
        $content=(string)($shot->title??'').' '.(string)($shot->visual??'').' '
            .(string)($shot->dialogue??'').' '.(string)($shot->action_before??'');
        $ranked=[];
        foreach($assets as $asset){
            $name=trim((string)$asset->name);
            if($name==='')continue;
            $compact=preg_replace('/[\s　_-]+/u','',$name);
            $text=preg_replace('/[\s　_-]+/u','',$content);
            $direct=mb_stripos($text,$compact)!==false;
            // Some script assets have type prefixes; check long tokens without
            // inventing a reference to a character not present in the project.
            $tokens=preg_split('/[·、,，:：()（）\s_-]+/u',$name,-1,PREG_SPLIT_NO_EMPTY);
            $alias=false;
            foreach($tokens?:[] as $token){
                if(mb_strlen($token)>=2 && mb_stripos($content,$token)!==false){$alias=true;break;}
            }
            $ranked[]=['name'=>$name,'direct'=>$direct||$alias,'has_image'=>!empty($asset->file_path)];
        }
        usort($ranked,static fn($a,$b)=>(int)$b['direct']<=>(int)$a['direct']);
        return $ranked;
    }
    public static function guidance(object $project,object $shot):string
    {
        $list=self::list($project,$shot);
        if(!$list)return '';
        $lines=["参考资产图片要求（请使用下列实际资产参考图，不将文字描述冒充真实图片；已折叠资产不包含在内）："];
        foreach($list as $item){
            $lines[]='- 请提供「'.$item['name'].'」图片'
                .($item['direct']?'（本镜重点参考）':'（按画面需要参考）');
        }
        $lines[]='真实品牌和型号的视觉外观必须以对应的参考图片为准，不能自行设计、猜测商标、型号、产品外形或 Logo。';
        return implode("\n",$lines);
    }
}
