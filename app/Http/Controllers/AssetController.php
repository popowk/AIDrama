<?php
namespace App\Http\Controllers;

use App\Services\AssetOrigin;
use App\Services\ImageEngine;
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

    public function upload(Request $request,int $id,int $asset)
    {
        [$project,$record]=$this->find($id,$asset);
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
            'image_status'=>'uploaded','image_started_at'=>null,'image_error'=>null,'updated_at'=>now(),
        ]);
        return back()->with('ok','已保存客户上传的真实参考素材：'.$record->name);
    }

    public function generate(int $id,int $asset,ImageEngine $engine)
    {
        [$project,$record]=$this->find($id,$asset);
        if(AssetOrigin::needsUpload($record->type,$record->name,
            (string)$record->description,$record->source_kind)) {
            return response()->json(['error'=>'此资产需要客户上传真实 Logo/产品/型号参考图，不支持 AI 重绘'],422);
        }
        $claimed=DB::table('assets')->where('id',$asset)->where('project_id',$id)
            ->where(function($q) {
                $q->where('image_status','!=','generating')
                  ->orWhere('image_started_at','<',now()->subMinutes(8));
            })->update([
                'image_status'=>'generating','image_started_at'=>now(),
                'image_error'=>null,'updated_at'=>now(),
            ]);
        if(!$claimed)return response()->json(['status'=>'generating','message'=>'图片任务已经在处理'],202);
        ignore_user_abort(true);
        if(function_exists('set_time_limit'))@set_time_limit(260);
        try {
            $data=$engine->generate($project,$record);
            $path='projects/'.$id.'/assets/'.bin2hex(random_bytes(16)).'.'.$data['extension'];
            if(!Storage::disk('local')->put($path,$data['data']))
                throw new RuntimeException('生成成功但服务器无法存储图片，请检查写入权限');
            $updated=DB::table('assets')->where('id',$asset)->where('project_id',$id)
                ->where('image_status','generating')->update([
                    'file_path'=>$path,'image_status'=>'ready','image_started_at'=>null,
                    'image_error'=>null,'updated_at'=>now(),
                ]);
            if(!$updated) {
                Storage::disk('local')->delete($path);
                return response()->json(['status'=>'cancelled','error'=>'任务状态已变更，生成结果未覆盖原素材'],409);
            }
            return response()->json(['status'=>'ready','image_url'=>route('asset.file',[$id,$asset])]);
        }catch(\Throwable $e) {
            $message=$e instanceof RuntimeException
                ?mb_substr($e->getMessage(),0,230)
                :'图片生成失败，请检查模型或服务器日志';
            DB::table('assets')->where('id',$asset)->where('project_id',$id)
                ->where('image_status','generating')->update([
                    'image_status'=>'error','image_error'=>$message,
                    'image_started_at'=>null,'updated_at'=>now(),
                ]);
            Log::warning('Asset image generation failed',[
                'project_id'=>$id,'asset_id'=>$asset,'exception'=>get_class($e),
            ]);
            return response()->json(['status'=>'error','error'=>$message],422);
        }
    }

    public function status(int $id,int $asset)
    {
        [,$a]=$this->find($id,$asset);
        $state=$a->image_status;
        if($state==='generating'&&$a->image_started_at&&
            \Illuminate\Support\Carbon::parse($a->image_started_at)->isBefore(now()->subMinutes(8))) {
            DB::table('assets')->where('id',$asset)->where('project_id',$id)
                ->where('image_status','generating')->update([
                    'image_status'=>'error','image_error'=>'上次图片生成超时，可重新提交',
                    'image_started_at'=>null,'updated_at'=>now(),
                ]);
            $state='error';
        }
        return response()->json([
            'status'=>$state,
            'error'=>$state==='error'?$a->image_error:null,
            'has_image'=>!empty($a->file_path),
        ]);
    }

    public function file(int $id,int $asset)
    {
        [,$a]=$this->find($id,$asset);
        if(!$a->file_path)abort(404);
        $base='projects/'.$id.'/assets/';
        if(!str_starts_with($a->file_path,$base)||
            !preg_match('~^projects/[0-9]+/assets/[a-f0-9]{32}\.(png|jpg|webp)$~D',$a->file_path))
            abort(404);
        $storage=Storage::disk('local');
        if(!$storage->exists($a->file_path))abort(404);
        $path=$storage->path($a->file_path);
        $mime=match(strtolower(pathinfo($path,PATHINFO_EXTENSION))) {
            'jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp',
            default=>'application/octet-stream',
        };
        return response()->file($path,[
            'Content-Type'=>$mime,
            'Content-Disposition'=>'inline',
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
