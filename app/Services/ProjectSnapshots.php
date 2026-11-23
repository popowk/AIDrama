<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Snapshots are private database copies, referencing existing PRIVATE images.
 * The image files are already retained in shared storage and are never deleted
 * by regeneration or snapshot restore. This class never writes files.
 */
final class ProjectSnapshots
{
    public static function capture(int $projectId,string $reason='before_regeneration',?int $sourceJobId=null):?int
    {
        if(!in_array($reason,['before_regeneration','before_restore'],true))
            throw new RuntimeException('不支持的版本快照类型');
        // Invoked from the same transaction that will replace existing rows.
        $project=DB::table('projects')->where('id',$projectId)->lockForUpdate()->first();
        if(!$project)throw new RuntimeException('项目不存在，无法创建版本快照');
        $shots=DB::table('shots')->where('project_id',$projectId)->orderBy('sequence')->get()
            ->map(static fn($row)=>(array)$row)->all();
        $assets=DB::table('assets')->where('project_id',$projectId)->orderBy('id')->get()
            ->map(static fn($row)=>(array)$row)->all();
        if(!$shots&&!$assets)return null; // no empty version for first creation
        $links=DB::table('shot_asset_links')->whereIn('shot_id',array_column($shots,'id'))
            ->get(['shot_id','asset_id'])->map(static fn($row)=>(array)$row)->all();
        $payload=json_encode([
            'version'=>2,
            'shots'=>$shots,
            'assets'=>$assets,
            'shot_asset_links'=>$links,
            'project_settings'=>[
                'detected_mode'=>$project->detected_mode??null,
                'shot_limit'=>$project->shot_limit,
                'style'=>$project->style,
                'video_mode'=>$project->video_mode??'short',
                'frame_ratio'=>$project->frame_ratio??'landscape',
            ],
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return DB::table('project_snapshots')->insertGetId([
            'project_id'=>$projectId,'reason'=>$reason,
            'source_job_id'=>$sourceJobId,
            'shot_count'=>count($shots),'asset_count'=>count($assets),
            'payload'=>$payload,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public static function list(int $projectId,int $limit=15)
    {
        return DB::table('project_snapshots')->where('project_id',$projectId)
            ->orderByDesc('id')->limit(max(1,min($limit,30)))
            ->get(['id','reason','source_job_id','shot_count','asset_count','created_at']);
    }

    /**
     * Atomically save the current version, then restore the selected old one.
     * The source script and original storyboard table are intentionally NOT
     * rewritten, so recovering an old generation never silently discards new
     * script edits. Physical image files remain private and untouched.
     */
    public static function restore(int $projectId,int $snapshotId):array
    {
        return DB::transaction(static function()use($projectId,$snapshotId){
            $project=DB::table('projects')->where('id',$projectId)->lockForUpdate()->first();
            if(!$project)throw new RuntimeException('项目不存在');
            if($project->status==='generating')
                throw new RuntimeException('生成任务正在运行，请完成后再恢复历史版本');
            $active=DB::table('generation_jobs')->where('project_id',$projectId)
                ->whereIn('status',['queued','running'])->exists();
            if($active)
                throw new RuntimeException('已有待处理或运行中的生成任务，暂不能恢复历史版本');
            if(DB::table('assets')->where('project_id',$projectId)
                ->where('image_status','generating')->exists())
                throw new RuntimeException('图片任务正在生成，请完成后再恢复，以免丢失结果');

            $snapshot=DB::table('project_snapshots')->where('project_id',$projectId)
                ->where('id',$snapshotId)->first();
            if(!$snapshot)throw new RuntimeException('该项目的历史版本不存在');
            try{$data=json_decode((string)$snapshot->payload,true,512,JSON_THROW_ON_ERROR);}
            catch(\JsonException $e){throw new RuntimeException('历史版本数据损坏，已保留现有分镜与资产');}
            if(!is_array($data)||!in_array($data['version']??null,[1,2],true)||
                !is_array($data['shots']??null)||!is_array($data['assets']??null)||
                (($data['version']??null)===2&&!is_array($data['shot_asset_links']??null))||
                count($data['shots'])!==(int)$snapshot->shot_count||
                count($data['assets'])!==(int)$snapshot->asset_count)
                throw new RuntimeException('历史版本数据校验失败，已保留当前数据');
            foreach(['shots','assets'] as $type){
                foreach($data[$type] as $record){
                    if(!is_array($record)||!(int)($record['id']??0)||
                        (int)($record['project_id']??0)!==$projectId)
                        throw new RuntimeException('历史版本包含无效数据，恢复已终止');
                }
            }

            $currentId=self::capture($projectId,'before_restore',null);
            DB::table('shots')->where('project_id',$projectId)->delete();
            DB::table('assets')->where('project_id',$projectId)->delete();
            $missingImages=0;
            foreach($data['shots'] as $shot)DB::table('shots')->insert($shot);
            foreach($data['assets'] as $asset){
                $path=(string)($asset['file_path']??'');
                if($path!==''&&!Storage::disk('local')->exists($path)){
                    $missingImages++;
                    $asset['file_path']=null;
                    $asset['image_status']='error';
                    $asset['image_error']='历史原图文件已不在存储目录中，请重新上传或生成';
                }
                // A historical in-flight task must not be silently revived.
                if(($asset['image_status']??null)==='generating'){
                    $asset['image_status']='error';
                    $asset['image_error']='历史图片任务已结束，请确认后手动重新生成';
                    $asset['image_task_id']=null;
                    $asset['image_started_at']=null;
                    $asset['image_poll_after']=null;
                }
                DB::table('assets')->insert($asset);
            }
            // Old v1 snapshots have no asset mapping. Their shots retain the
            // DB default auto-matching mode; newer v2 snapshots preserve
            // user-selected links, including an explicitly empty selection.
            if(($data['version']??null)===2){
                $shotIds=array_fill_keys(array_map('intval',array_column($data['shots'],'id')),true);
                $assetIds=array_fill_keys(array_map('intval',array_column($data['assets'],'id')),true);
                foreach($data['shot_asset_links'] as $link){
                    $sid=(int)($link['shot_id']??0);
                    $aid=(int)($link['asset_id']??0);
                    if(!isset($shotIds[$sid])||!isset($assetIds[$aid]))
                        throw new RuntimeException('历史资产关联不完整，已取消恢复并保留现有版本');
                    DB::table('shot_asset_links')->insert([
                        'shot_id'=>$sid,'asset_id'=>$aid,
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            }
            $settings=$data['project_settings']??[];
            $maxSeconds=0;
            foreach($data['shots'] as $shot)
                $maxSeconds=max($maxSeconds,(int)($shot['duration_seconds']??0));
            $savedLimit=(int)($settings['shot_limit']??15);
            $shotLimit=($savedLimit===30||$maxSeconds>15)?30:15;
            DB::table('projects')->where('id',$projectId)->update([
                'status'=>count($data['shots'])?'ready':'draft',
                'shot_limit'=>$shotLimit,
                'detected_mode'=>$settings['detected_mode']??null,
                'updated_at'=>now(),
            ]);
            return [
                'restored_snapshot_id'=>$snapshotId,
                'safety_snapshot_id'=>$currentId,
                'shots'=>count($data['shots']),
                'assets'=>count($data['assets']),
                'missing_images'=>$missingImages,
            ];
        });
    }
}
