<?php
namespace App\Http\Controllers;

use App\Services\AssetOrigin;
use App\Services\AssetImagePrompt;
use App\Services\ImageEngine;
use App\Services\ChatgptImageTasks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AssetController
{
    private function find(int $projectId,int $assetId):array
    {
        $project=DB::table('projects')->where('id',$projectId)->first();
        $asset=DB::table('assets')->where('id',$assetId)->where('project_id',$projectId)->first();
        if(!$project||!$asset)abort(404);
        return [$project,$asset];
    }

    private function saveReply(string $message,array $data,string $fallback)
    {
        if(request()->expectsJson())return response()->json(['ok'=>true,'message'=>$message]+$data);
        return redirect()->to($fallback)->with('ok',$message);
    }

    private function refused(string $message,string $fallback)
    {
        if(request()->expectsJson())return response()->json(['ok'=>false,'message'=>$message],409);
        return redirect()->to($fallback)->withErrors(['asset'=>$message]);
    }

    public function archive(int $id,int $asset)
    {
        [$project,$record]=$this->find($id,$asset);
        $fallback=route('project.show',$id).'#asset-'.$asset;
        if($project->status==='generating'||$record->image_status==='generating')
            return $this->refused('生成任务运行期间不能折叠资产，请稍后再试。',$fallback);
        DB::table('assets')->where('id',$asset)->where('project_id',$id)->update([
            'is_archived'=>true,'updated_at'=>now(),
        ]);
        return $this->saveReply('已折叠「'.$record->name.'」，图片和提示词均已保留。',
            ['asset_id'=>$asset,'archived'=>true],$fallback);
    }

    public function restore(int $id,int $asset)
    {
        [$project,$record]=$this->find($id,$asset);
        $fallback=route('project.show',$id).'#asset-'.$asset;
        if($project->status==='generating'||$record->image_status==='generating')
            return $this->refused('生成任务运行期间暂时不能恢复资产。',$fallback);
        DB::table('assets')->where('id',$asset)->where('project_id',$id)->update([
            'is_archived'=>false,'updated_at'=>now(),
        ]);
        return $this->saveReply('已恢复资产「'.$record->name.'」，原图与提示词保持不变。',
            ['asset_id'=>$asset,'archived'=>false],$fallback);
    }

    public function savePrompt(Request $request,int $id,int $asset)
    {
        [$project,$record]=$this->find($id,$asset);
        $fallback=route('project.show',$id).'#asset-'.$asset;
        if($project->status==='generating'||$record->image_status==='generating'||
            (bool)($record->is_archived??false))
            return $this->refused('生成中或已折叠的资产暂时不能编辑提示词。',$fallback);
        $value=$request->validate(['prompt'=>'nullable|string|max:30000']);
        $prompt=trim((string)($value['prompt']??''));
        DB::table('assets')->where('id',$asset)->where('project_id',$id)->update([
            'prompt'=>$prompt!==''?$prompt:$record->prompt,
            'prompt_override'=>$prompt!=='',
            'updated_at'=>now(),
        ]);
        $updated=DB::table('assets')->where('id',$asset)->where('project_id',$id)->first();
        return $this->saveReply(
            $prompt===''?'已恢复此资产的类别提示词。':'该资产提示词已保存，下次生成图片时使用。',
            ['asset_id'=>$asset,'prompt'=>AssetImagePrompt::build($project,$updated),
                'prompt_override'=>(bool)$updated->prompt_override],$fallback);
    }

    public function resetPrompt(int $id,int $asset)
    {
        [$project,$record]=$this->find($id,$asset);
        $fallback=route('project.show',$id).'#asset-'.$asset;
        if($project->status==='generating'||$record->image_status==='generating'||
            (bool)($record->is_archived??false))
            return $this->refused('生成中或已折叠的资产暂时不能恢复提示词。',$fallback);
        DB::table('assets')->where('id',$asset)->where('project_id',$id)->update([
            'prompt_override'=>false,'updated_at'=>now(),
        ]);
        $updated=DB::table('assets')->where('id',$asset)->where('project_id',$id)->first();
        return $this->saveReply('已恢复该资产使用的类别模板。',
            ['asset_id'=>$asset,'prompt'=>AssetImagePrompt::build($project,$updated),
                'prompt_override'=>false],$fallback);
    }

    public function frameRatio(Request $request,int $id,int $asset)
    {
        [$project,$record]=$this->find($id,$asset);
        $fallback=route('project.show',$id).'#asset-'.$asset;
        $data=$request->validate([
            'frame_ratio'=>['required',\Illuminate\Validation\Rule::in(['inherit','landscape','portrait','square'])],
        ]);
        if($project->status==='generating'||$record->image_status==='generating'||
            (bool)($record->is_archived??false))
            return $this->refused('生成中或已折叠的资产暂时不能修改画幅。',$fallback);
        DB::table('assets')->where('id',$asset)->where('project_id',$id)->update([
            'frame_ratio'=>$data['frame_ratio'],'updated_at'=>now(),
        ]);
        $updated=DB::table('assets')->where('id',$asset)->where('project_id',$id)->first();
        return $this->saveReply('画幅已保存，下次生成图片时生效。',
            ['asset_id'=>$asset,'frame_ratio'=>$data['frame_ratio'],
                'prompt'=>AssetImagePrompt::build($project,$updated),
                'prompt_override'=>(bool)$updated->prompt_override],$fallback);
    }

    public function upload(Request $request,int $id,int $asset)
    {
        [$project,$record]=$this->find($id,$asset);
        if($project->status==='generating'||$record->image_status==='generating'||
            (bool)($record->is_archived??false))
            return $this->refused('生成中或已折叠的资产暂时不能上传图片。',
                route('project.show',$id).'#asset-'.$asset);
        $request->validate(['image'=>'required|file|mimes:png,jpg,jpeg,webp|max:10240']);
        $file=$request->file('image');
        $info=@getimagesize($file->getRealPath());
        if(!$info||!in_array($info['mime']??'',['image/png','image/jpeg','image/webp'],true)||
            $info[0]>8192||$info[1]>8192)
            return back()->withErrors(['image'=>'图片必须是真实 JPG/PNG/WebP 文件，长宽不超过 8192 像素']);
        $extension=match($info['mime']) {
            'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp',
        };
        $base='projects/'.$id.'/assets';
        $stored=Storage::disk('local')->putFileAs($base,$file,bin2hex(random_bytes(16)).'.'.$extension);
        if(!$stored)throw new RuntimeException('无法保存上传素材，请检查 shared/storage 私有目录权限');
        DB::table('assets')->where('id',$asset)->where('project_id',$id)->update([
            'file_path'=>$stored,'source_kind'=>'upload',
            'image_status'=>'uploaded','image_started_at'=>null,'image_error'=>null,
            'image_task_id'=>null,'image_remote_status'=>null,'image_poll_after'=>null,
            'image_resume_attempted'=>false,'updated_at'=>now(),
        ]);
        return $this->saveReply('已保存客户上传的真实参考素材：'.$record->name,
            ['asset_id'=>$asset,'has_image'=>true,
                'image_url'=>route('asset.file',[$id,$asset]),
                'thumbnail_url'=>route('asset.thumbnail',[$id,$asset]),
                'download_url'=>route('asset.download',[$id,$asset])],
            route('project.show',$id).'#asset-'.$asset);
    }

    private function imageError(int $id,int $asset,\Throwable $e):string
    {
        $incident=bin2hex(random_bytes(5));
        $reason=$e instanceof RuntimeException
            ?mb_substr($e->getMessage(),0,220)
            :'图片任务发生服务器错误，请检查日志';
        Log::warning('Asset image task failed',[
            'incident'=>$incident,'project_id'=>$id,'asset_id'=>$asset,
            'exception'=>get_class($e),
            'reason'=>$e instanceof RuntimeException?$reason:'internal_error',
            'source'=>basename($e->getFile()).':'.$e->getLine(),
        ]);
        return $reason.'（编号 '.$incident.'）';
    }

    public function generate(int $id,int $asset,ImageEngine $engine)
    {
        [$project,$record]=$this->find($id,$asset);
        if((bool)($record->is_archived??false))
            return response()->json(['error'=>'此资产已移除，请先恢复再生成图片。'],422);
        if(AssetOrigin::needsUpload($record->type,$record->name,
            (string)$record->description,$record->source_kind))
            return response()->json(['error'=>'Logo、客户产品及具体型号必须上传真实参考图，不能 AI 生成'],422);

        $mode=DB::table('settings')->where('key','ai.image.delivery')->value('value');
        // The user-selected chatgpt2api 3.x task protocol is the default;
        // generic OpenAI-compatible image APIs can opt into legacy sync mode.
        if($mode==='openai_sync')return $this->generateSync($id,$asset,$project,$record,$engine);

        $tasks=app(ChatgptImageTasks::class);
        try {$tasks->config();}
        catch(\Throwable $e) {
            return response()->json(['status'=>'error','error'=>$this->imageError($id,$asset,$e)],422);
        }
        if($record->image_status==='generating')
            return response()->json(['status'=>'generating','message'=>'已存在图片生成任务；正在查询原任务，不会重复计费'],202);

        // Unknown outcomes are NOT permission to issue a new paid task.
        if($record->image_task_id && $record->image_remote_status!=='failed') {
            DB::table('assets')->where('id',$asset)->where('project_id',$id)
                ->where('image_task_id',$record->image_task_id)->update([
                    'image_status'=>'generating','image_poll_after'=>null,
                    'image_error'=>null,'updated_at'=>now(),
                ]);
            return response()->json(['status'=>'generating','message'=>'继续查询上次任务，不会创建新任务'],202);
        }

        $taskId='aidrama-'.bin2hex(random_bytes(16));
        $query=DB::table('assets')->where('id',$asset)->where('project_id',$id)
            ->where('image_status','!=','generating');
        if($record->image_task_id)
            $query->where('image_task_id',$record->image_task_id)->where('image_remote_status','failed');
        else
            $query->whereNull('image_task_id');
        $claimed=$query->update([
            'image_task_id'=>$taskId,'image_status'=>'generating',
            'image_started_at'=>now(),'image_poll_after'=>now()->addSeconds(4),
            'image_remote_status'=>'submitting','image_resume_attempted'=>false,
            'image_error'=>null,'updated_at'=>now(),
        ]);
        if(!$claimed)
            return response()->json(['status'=>'generating','message'=>'任务已被另一请求领取，请刷新状态'],202);
        try {
            $remote=$tasks->submit($taskId,$project,$record);
            DB::table('assets')->where('id',$asset)->where('image_task_id',$taskId)
                ->where('image_status','generating')->update([
                    'image_remote_status'=>in_array($remote['status']??'',['queued','running'],true)
                        ?$remote['status']:'submitting',
                    'image_poll_after'=>now()->addSeconds(3),'updated_at'=>now(),
                ]);
            return response()->json([
                'status'=>'generating','message'=>'图片任务已提交，系统将自动查询并保存结果',
            ],202);
        }catch(\Throwable $e) {
            // A proxy timeout does NOT prove the provider failed to accept the
            // task. Keep the same client_task_id and reconcile it by polling.
            $message=$this->imageError($id,$asset,$e);
            DB::table('assets')->where('id',$asset)->where('image_task_id',$taskId)
                ->where('image_status','generating')->update([
                    'image_error'=>'提交响应暂未确认：'.$message,
                    'image_poll_after'=>now()->addSeconds(8),'updated_at'=>now(),
                ]);
            return response()->json([
                'status'=>'generating',
                'message'=>'提交确认暂时失败，正在按原任务编号查找，不会重复提交新任务',
            ],202);
        }
    }

    private function generateSync(int $id,int $asset,object $project,object $record,ImageEngine $engine)
    {
        $claimed=DB::table('assets')->where('id',$asset)->where('project_id',$id)
            ->where('image_status','!=','generating')->update([
                'image_status'=>'generating','image_started_at'=>now(),
                'image_task_id'=>null,'image_remote_status'=>null,
                'image_poll_after'=>null,'image_resume_attempted'=>false,
                'image_error'=>null,'updated_at'=>now(),
            ]);
        if(!$claimed)return response()->json(['status'=>'generating','message'=>'图片任务已经在处理'],202);
        ignore_user_abort(true);
        if(function_exists('set_time_limit'))@set_time_limit(260);
        try {
            $data=$engine->generate($project,$record);
            $path='projects/'.$id.'/assets/'.bin2hex(random_bytes(16)).'.'.$data['extension'];
            if(!Storage::disk('local')->put($path,$data['data']))
                throw new RuntimeException('无法保存生成图片，请检查私有存储目录权限');
            $updated=DB::table('assets')->where('id',$asset)->where('project_id',$id)
                ->where('image_status','generating')->whereNull('image_task_id')->update([
                    'file_path'=>$path,'image_status'=>'ready','image_started_at'=>null,
                    'image_error'=>null,'updated_at'=>now(),
                ]);
            if(!$updated){Storage::disk('local')->delete($path);return response()->json(['status'=>'cancelled'],409);}
            return response()->json(['status'=>'ready','image_url'=>route('asset.file',[$id,$asset])]);
        }catch(\Throwable $e){
            $message=$this->imageError($id,$asset,$e);
            DB::table('assets')->where('id',$asset)->where('project_id',$id)
                ->where('image_status','generating')->whereNull('image_task_id')->update([
                    'image_status'=>'error','image_error'=>$message,
                    'image_started_at'=>null,'updated_at'=>now(),
                ]);
            return response()->json(['status'=>'error','error'=>$message],422);
        }
    }

    public function status(int $id,int $asset)
    {
        [$project,$record]=$this->find($id,$asset);
        if($record->image_status!=='generating')
            return $this->imageState($record);

        if(!$record->image_task_id) {
            // Legacy OpenAI synchronous image request: no remote task exists.
            if($record->image_started_at &&
               \Illuminate\Support\Carbon::parse($record->image_started_at)->isBefore(now()->subMinutes(8))) {
                DB::table('assets')->where('id',$asset)->where('project_id',$id)
                    ->where('image_status','generating')->whereNull('image_task_id')->update([
                        'image_status'=>'error','image_error'=>'同步生图请求已超时，请检查上游任务记录',
                        'image_started_at'=>null,'updated_at'=>now(),
                    ]);
                $record=DB::table('assets')->where('id',$asset)->first();
            }
            return $this->imageState($record);
        }

        $taskId=(string)$record->image_task_id;
        $claimed=DB::table('assets')->where('id',$asset)->where('project_id',$id)
            ->where('image_task_id',$taskId)->where('image_status','generating')
            ->where(function($q){
                $q->whereNull('image_poll_after')->orWhere('image_poll_after','<=',now());
            })->update(['image_poll_after'=>now()->addSeconds(6)]);
        if(!$claimed)return $this->imageState($record);

        $tasks=app(ChatgptImageTasks::class);
        try {
            $remote=$tasks->check($taskId);
            if(!$remote) {
                $started=$record->image_started_at
                    ?\Illuminate\Support\Carbon::parse($record->image_started_at):now();
                $age=$started->diffInSeconds(now());
                if($age>1800) {
                    // Do not re-submit after a very long unknown outcome.
                    DB::table('assets')->where('id',$asset)->where('image_task_id',$taskId)
                        ->where('image_status','generating')->update([
                            'image_status'=>'error','image_remote_status'=>'missing',
                            'image_error'=>'上游长时间未找到任务，请先核对 chatgpt2api 历史任务，避免重复计费',
                            'updated_at'=>now(),
                        ]);
                }elseif($age>=15 && in_array($record->image_remote_status,['submitting','missing'],true)) {
                    // Idempotent by client_task_id; a lost POST response may
                    // have been accepted, and resubmission returns that task.
                    $tasks->submit($taskId,$project,$record);
                    DB::table('assets')->where('id',$asset)->where('image_task_id',$taskId)
                        ->where('image_status','generating')->update([
                            'image_remote_status'=>'queued','image_poll_after'=>now()->addSeconds(5),
                            'image_error'=>null,'updated_at'=>now(),
                        ]);
                }
            }else{
                $remoteStatus=(string)($remote['status']??'');
                if(in_array($remoteStatus,['queued','running'],true)){
                    DB::table('assets')->where('id',$asset)->where('image_task_id',$taskId)
                        ->where('image_status','generating')->update([
                            'image_remote_status'=>$remoteStatus,'image_error'=>null,'updated_at'=>now(),
                        ]);
                }elseif(in_array($remoteStatus,['success','partial_success'],true)) {
                    // First store the image safely, then commit the new path.
                    // If download fails, keep the remote ID to retry next poll.
                    $image=$tasks->image($remote);
                    $path='projects/'.$id.'/assets/'.bin2hex(random_bytes(16)).'.'.$image['extension'];
                    if(!Storage::disk('local')->put($path,$image['data']))
                        throw new RuntimeException('上游已生成，但无法保存到 AIDrama 私有存储目录');
                    $updated=DB::table('assets')->where('id',$asset)->where('project_id',$id)
                        ->where('source_kind','generate')->where('image_task_id',$taskId)
                        ->where('image_status','generating')->update([
                            'file_path'=>$path,'image_status'=>'ready',
                            'image_remote_status'=>'success','image_started_at'=>null,
                            'image_error'=>null,'image_poll_after'=>null,'updated_at'=>now(),
                        ]);
                    if(!$updated)Storage::disk('local')->delete($path);
                }elseif(in_array($remoteStatus,['failed','text_review','error'],true)){
                    $actions=$remote['actions']??[];
                    if(($actions['resume_poll']??false)&&!$record->image_resume_attempted) {
                        $granted=DB::table('assets')->where('id',$asset)->where('image_task_id',$taskId)
                            ->where('image_resume_attempted',false)->update([
                                'image_resume_attempted'=>true,
                                'image_remote_status'=>'resuming','updated_at'=>now(),
                            ]);
                        if($granted)$tasks->resume($taskId);
                    }elseif($record->image_remote_status!=='resuming') {
                        DB::table('assets')->where('id',$asset)->where('image_task_id',$taskId)
                            ->where('image_status','generating')->update([
                                'image_status'=>'error','image_remote_status'=>'failed',
                                'image_error'=>mb_substr((string)($remote['public_error']??'上游图片任务失败'),0,230),
                                'image_started_at'=>null,'updated_at'=>now(),
                            ]);
                    }else{
                        // The just-resumed task may still appear failed for one
                        // eventual-consistency poll. Recheck rather than pay again.
                        DB::table('assets')->where('id',$asset)->where('image_task_id',$taskId)
                            ->where('image_status','generating')->update([
                                'image_remote_status'=>'failed','image_poll_after'=>now()->addSeconds(10),
                                'image_error'=>'恢复轮询后仍未找到图片结果，请检查上游历史',
                            ]);
                    }
                }
            }
        }catch(\Throwable $e) {
            $message=$this->imageError($id,$asset,$e);
            DB::table('assets')->where('id',$asset)->where('image_task_id',$taskId)
                ->where('image_status','generating')->update([
                    'image_error'=>'正在重试查询或获取生成结果：'.$message,
                    'image_poll_after'=>now()->addSeconds(12),'updated_at'=>now(),
                ]);
        }
        return $this->imageState(DB::table('assets')->where('id',$asset)->first());
    }

    private function imageState(object $a)
    {
        return response()->json([
            'status'=>$a->image_status,
            'stage'=>$a->image_remote_status??null,
            'message'=>($a->image_status==='generating'&&$a->image_error)
                ?$a->image_error:null,
            'error'=>$a->image_status==='error'?$a->image_error:null,
            'has_image'=>!empty($a->file_path),
            'image_url'=>$a->file_path?route('asset.file',[$a->project_id,$a->id]):null,
            'thumbnail_url'=>$a->file_path?route('asset.thumbnail',[$a->project_id,$a->id]):null,
            'download_url'=>$a->file_path?route('asset.download',[$a->project_id,$a->id]):null,
            'task_id'=>$a->image_task_id??null,
        ]);
    }

    /**
     * All image delivery endpoints share the same project-scoped private-path
     * validation. Never read a filename from a request parameter.
     */
    private function storedImage(int $id,int $asset):array
    {
        [,$a]=$this->find($id,$asset);
        $relative=(string)($a->file_path??'');
        $prefix='projects/'.$id.'/assets/';
        if(!str_starts_with($relative,$prefix)||
            !preg_match('~^projects/[0-9]+/assets/[a-f0-9]{32}\.(png|jpg|webp)$~D',$relative))
            abort(404);
        $storage=Storage::disk('local');
        if(!$storage->exists($relative))abort(404);
        $extension=strtolower(pathinfo($relative,PATHINFO_EXTENSION));
        $mime=match($extension) {
            'jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp',
            default=>'application/octet-stream',
        };
        return [$a,$storage->path($relative),$mime,$extension];
    }

    /**
     * Browser cache is private and versioned by the actual stored file path.
     * ETags allow bandwidth-free 304 validation after seven days. Authentication
     * is still enforced by the route middleware before cache revalidation.
     */
    private function cachedImage(string $path,string $mime):\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $response=response()->file($path,[
            'Content-Type'=>$mime,
            'Content-Disposition'=>'inline',
            'Cache-Control'=>'private, max-age=604800, must-revalidate',
            'Vary'=>'Cookie',
            'X-Content-Type-Options'=>'nosniff',
        ]);
        $modified=@filemtime($path)?:time();
        $size=@filesize($path)?:0;
        $response->setEtag(hash('sha256',$path.':'.$modified.':'.$size));
        $response->setLastModified(new \DateTimeImmutable('@'.$modified));
        $response->isNotModified(request());
        return $response;
    }

    public function file(int $id,int $asset)
    {
        [,$path,$mime]=$this->storedImage($id,$asset);
        return $this->cachedImage($path,$mime);
    }

    /**
     * Download the full-resolution private asset, using the asset's own name.
     * The server-generated Content-Disposition works in browsers that ignore
     * the HTML download attribute or do not support non-ASCII names there.
     */
    public function download(int $id,int $asset)
    {
        [$a,$path,$mime,$extension]=$this->storedImage($id,$asset);
        $name=trim((string)preg_replace('/[\\\\\/\x00-\x1F\x7F:*?"<>|]+/u','_', (string)$a->name));
        $name=trim(mb_substr($name,0,90),'. ');
        if($name==='')$name='资产-'.$asset;
        $filename=$name.'.'.$extension;
        $response=response()->file($path,[
            'Content-Type'=>$mime,
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
        $response->setContentDisposition('attachment',$filename,'asset-'.$asset.'.'.$extension);
        return $response;
    }

    /**
     * Keep originals untouched. The first preview request builds an optional
     * private 320px WebP thumbnail, which is cached by immutable source path.
     * If GD cannot process the image safely, serve the original in the same
     * visually constrained <img> element rather than failing the page.
     */
    public function thumbnail(int $id,int $asset)
    {
        [$a,$path,$mime]=$this->storedImage($id,$asset);
        if(!function_exists('imagecreatefromstring')||!function_exists('imagewebp'))
            return $this->file($id,$asset);

        $info=@getimagesize($path);
        if(!is_array($info) || ($info[0]??0)<1 || ($info[1]??0)<1 ||
           $info[0]*$info[1]>12000000)
            return $this->file($id,$asset);

        $storage=Storage::disk('local');
        $cache='thumbnails/'.hash('sha256',(string)$a->file_path).'-320.webp';
        if(!$storage->exists($cache)) {
            $source=@file_get_contents($path);
            if(!is_string($source)||strlen($source)>20*1024*1024)
                return $this->file($id,$asset);
            $src=@imagecreatefromstring($source);
            unset($source);
            if($src===false)return $this->file($id,$asset);
            $width=imagesx($src);
            $height=imagesy($src);
            $factor=min(1,320/max($width,$height));
            $tw=max(1,(int)round($width*$factor));
            $th=max(1,(int)round($height*$factor));
            $thumb=imagecreatetruecolor($tw,$th);
            if($thumb===false){imagedestroy($src);return $this->file($id,$asset);}
            imagealphablending($thumb,false);
            imagesavealpha($thumb,true);
            $transparent=imagecolorallocatealpha($thumb,0,0,0,127);
            imagefill($thumb,0,0,$transparent);
            imagecopyresampled($thumb,$src,0,0,0,0,$tw,$th,$width,$height);
            ob_start();
            $saved=@imagewebp($thumb,null,78);
            $bytes=ob_get_clean();
            imagedestroy($thumb);
            imagedestroy($src);
            if(!$saved||!is_string($bytes)||strlen($bytes)<30||
               !$storage->put($cache,$bytes))
                return $this->file($id,$asset);
        }
        return $this->cachedImage($storage->path($cache),'image/webp');
    }
}
