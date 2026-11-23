<?php
namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ShotAssetSelection
{
    public static function activeIds(int $projectId,array $ids):array
    {
        $ids=array_values(array_unique(array_filter(array_map(
            static fn($v)=>filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]),$ids
        ),static fn($v)=>$v!==false)));
        if(!$ids)return [];
        return DB::table('assets')->where('project_id',$projectId)
            ->where('is_archived',false)->whereIn('id',$ids)
            ->pluck('id')->map(static fn($v)=>(int)$v)->all();
    }

    public static function save(int $projectId,int $shotId,array $ids,string $mode='manual'):array
    {
        if(!in_array($mode,['ai','manual'],true))
            throw new RuntimeException('不支持的资产匹配模式');
        return DB::transaction(static function()use($projectId,$shotId,$ids,$mode){
            $project=DB::table('projects')->where('id',$projectId)->lockForUpdate()->first();
            if(!$project)throw new RuntimeException('项目不存在');
            if($project->status==='generating')
                throw new RuntimeException('分镜正在生成，请完成后再调整参考资产');
            $shot=DB::table('shots')->where('project_id',$projectId)->where('id',$shotId)->first();
            if(!$shot)throw new RuntimeException('本镜头已不存在');
            $chosen=self::activeIds($projectId,$ids);
            DB::table('shot_asset_links')->where('shot_id',$shotId)->delete();
            foreach($chosen as $assetId)
                DB::table('shot_asset_links')->insert([
                    'shot_id'=>$shotId,'asset_id'=>$assetId,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            DB::table('shots')->where('id',$shotId)->update([
                'asset_selection_mode'=>$mode,'updated_at'=>now(),
            ]);
            return $chosen;
        });
    }

    public static function forProject(int $projectId,iterable $shots,iterable $assets):array
    {
        $shots=collect($shots);
        $assets=collect($assets);
        $links=DB::table('shot_asset_links')->whereIn('shot_id',$shots->pluck('id'))
            ->get(['shot_id','asset_id'])->groupBy('shot_id');
        $result=[];
        foreach($shots as $shot){
            $mode=in_array($shot->asset_selection_mode??'auto',['ai','manual'],true)
                ?$shot->asset_selection_mode:'auto';
            if($mode==='auto'){
                $ids=array_map(static fn($a)=>(int)$a['id'],
                    AssetReferencePicker::fromAssets($assets,$shot));
            }else{
                $ids=$links->get($shot->id,collect())->pluck('asset_id')
                    ->map(static fn($v)=>(int)$v)->all();
            }
            $result[(string)$shot->id]=[
                'mode'=>$mode,'selected_ids'=>array_values(array_unique($ids)),
            ];
        }
        return $result;
    }

    public static function projectReferences(int $projectId):array
    {
        $project=DB::table('projects')->where('id',$projectId)->first();
        if(!$project)return [];
        $assets=DB::table('assets')->where('project_id',$projectId)->get();
        $shots=DB::table('shots')->where('project_id',$projectId)->orderBy('sequence')->get();
        $selections=self::forProject($projectId,$shots,$assets);
        $lookup=$assets->keyBy('id');
        $result=[];
        foreach($shots as $shot){
            $refs=[];
            foreach($selections[(string)$shot->id]['selected_ids']??[] as $assetId){
                $asset=$lookup->get($assetId);
                if(!$asset||$asset->is_archived)continue;
                $refs[]=[
                    'id'=>(int)$asset->id,'name'=>$asset->name,'type'=>$asset->type,
                    'required'=>true,'direct'=>true,'has_image'=>!empty($asset->file_path),
                ];
            }
            $result[(string)$shot->id]=$refs;
        }
        return $result;
    }

    public static function references(object $project,object $shot,?array $ids=null):array
    {
        if($ids===null){
            if(in_array($shot->asset_selection_mode??'auto',['ai','manual'],true))
                $ids=DB::table('shot_asset_links')->where('shot_id',$shot->id)
                    ->pluck('asset_id')->all();
            else return AssetReferencePicker::list($project,$shot);
        }
        $valid=self::activeIds((int)$project->id,$ids);
        if(!$valid)return [];
        $assets=DB::table('assets')->where('project_id',$project->id)
            ->where('is_archived',false)->whereIn('id',$valid)->get()->keyBy('id');
        $result=[];
        foreach($valid as $id){
            $asset=$assets->get($id);
            if(!$asset)continue;
            $result[]=[
                'id'=>(int)$asset->id,'name'=>$asset->name,'type'=>$asset->type,
                'required'=>true,'direct'=>true,'has_image'=>!empty($asset->file_path),
            ];
        }
        return $result;
    }

    public static function guidance(object $project,object $shot,?array $ids=null):string
    {
        $references=self::references($project,$shot,$ids);
        if(!$references)return '';
        $lines=['本镜参考资产图片（只选当前勾选且未折叠的资产，不代表图片文件已上传给视频模型）：'];
        foreach($references as $asset)
            $lines[]='- 请提供「'.$asset['name'].'」图片（本镜选用）';
        $lines[]='真实品牌/型号及包装标识应以实际参考图为准，不得杜撰外观或 Logo。';
        return implode("\n",$lines);
    }

    /** Calls configured text model; invalid outputs never overwrite manual picks. */
    public static function recommend(object $project,object $shot):array
    {
        $assets=DB::table('assets')->where('project_id',$project->id)
            ->where('is_archived',false)->orderBy('id')->limit(100)
            ->get(['id','type','name','description']);
        if($assets->isEmpty())return [];
        $keys=DB::table('settings')->whereIn('key',[
            'ai.text.api_base','ai.api_base','ai.text.api_key','ai.api_key',
            'ai.text.model','ai.model',
        ])->pluck('value','key');
        $enc=(string)($keys['ai.text.api_key']??$keys['ai.api_key']??'');
        $model=trim((string)($keys['ai.text.model']??$keys['ai.model']??''));
        $base=rtrim((string)($keys['ai.text.api_base']??$keys['ai.api_base']??'https://api.openai.com/v1'),'/');
        if($enc===''||$model==='')throw new RuntimeException('请先在模型设置中配置文本模型和 API Key');
        try{$secret=Crypt::decryptString($enc);}
        catch(\Throwable $e){throw new RuntimeException('模型密钥解密失败，请重新设置');}
        if(!preg_match('~^https://([a-zA-Z0-9.-]+)(?::443)?(?:/[a-zA-Z0-9._/-]*)?$~D',$base,$m)
            ||strtolower($m[1])==='localhost'||str_ends_with(strtolower($m[1]),'.local')
            ||filter_var($m[1],FILTER_VALIDATE_IP))
            throw new RuntimeException('模型接口必须使用可信公网 HTTPS 域名');
        $candidates=$assets->map(static fn($a)=>[
            'id'=>(int)$a->id,'type'=>$a->type,'name'=>$a->name,
            'description'=>mb_substr((string)$a->description,0,300)
        ])->all();
        $shotData=[
            'title'=>$shot->title,'visual'=>$shot->visual,
            'dialogue'=>$shot->dialogue??'','action_before'=>$shot->action_before??'',
        ];
        $response=Http::withToken($secret)->acceptJson()->asJson()
            ->withoutRedirecting()->connectTimeout(12)->timeout(145)
            ->post($base.'/chat/completions',[
                'model'=>$model,'temperature'=>0,
                'messages'=>[
                    ['role'=>'system','content'=>'你是影视分镜资产匹配员。根据本镜画面、动作、人物对话，从候选资产中选择本镜实际需要参考图的角色、场景和道具。可以识别别名，但不能因为品牌相同就把所有产品、包装、商标加入；没出现在画面的资产不要选。只输出合法 JSON 对象 {"asset_ids":[整数ID,...]}，允许空数组。'],
                    ['role'=>'user','content'=>json_encode([
                        'shot'=>$shotData,'assets'=>$candidates,
                    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)],
                ],
            ]);
        if(!$response->successful())
            throw new RuntimeException('AI 识别参考资产失败（HTTP '.$response->status().'），请检查模型和余额');
        $raw=$response->json('choices.0.message.content');
        if(is_array($raw))$raw=implode("\n",array_map(
            static fn($v)=>is_array($v)?(string)($v['text']??''):'',$raw
        ));
        if(!is_string($raw)||trim($raw)==='')
            throw new RuntimeException('AI 没有返回有效资产识别结果');
        $raw=(string)preg_replace('/^\x60{3}(?:json)?\s*|\s*\x60{3}$/ui','',trim($raw));
        try{$parsed=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
        catch(\JsonException $e){throw new RuntimeException('AI 返回的资产识别结果不是有效 JSON，请重试');}
        if(!is_array($parsed)||!isset($parsed['asset_ids'])||!is_array($parsed['asset_ids']))
            throw new RuntimeException('AI 返回内容缺少 asset_ids 数组');
        $available=array_fill_keys($assets->pluck('id')->map(static fn($x)=>(int)$x)->all(),true);
        $chosen=[];
        foreach($parsed['asset_ids'] as $candidate){
            if(!is_int($candidate)&&!(is_string($candidate)&&ctype_digit($candidate)))
                continue;
            $id=(int)$candidate;
            if(isset($available[$id]))$chosen[$id]=$id;
        }
        return array_values($chosen);
    }
}
