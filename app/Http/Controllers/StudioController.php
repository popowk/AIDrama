<?php
namespace App\Http\Controllers;

use App\Services\StoryboardEngine;
use App\Services\PromptBuilder;
use App\Services\PromptPresets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

class StudioController
{
    public function index() {
        $projects=DB::table('projects')->orderByDesc('updated_at')->paginate(20);
        return view('dashboard',compact('projects'));
    }
    public function create(Request $request) {
        $v=$request->validate([
            'name'=>'required|string|max:191','script'=>'required|string|max:100000',
            'style'=>'required|string|max:191','shot_limit'=>['required',Rule::in([15,30])],
            'script_mode'=>['sometimes',Rule::in(['auto','talking','mixed'])],
        ]);
        $id=DB::table('projects')->insertGetId([
            'name'=>$v['name'],'script'=>$v['script'],'style'=>$v['style'],
            'shot_limit'=>(int)$v['shot_limit'],
            'script_mode'=>$v['script_mode']??'auto','status'=>'draft',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return redirect()->route('project.show',$id)->with('ok','项目已建立');
    }
    private function project(int $id): object {
        return DB::table('projects')->where('id',$id)->first() ?? abort(404);
    }
    public function show(int $id) {
        $project=$this->project($id);
        $shots=DB::table('shots')->where('project_id',$id)->orderBy('sequence')->get();
        $assets=DB::table('assets')->where('project_id',$id)->orderBy('type')->orderBy('id')->get();
        $total=$shots->sum('duration_seconds');
        return view('project',compact('project','shots','assets','total'));
    }
    public function update(Request $request,int $id) {
        $this->project($id);
        if(DB::table('projects')->where('id',$id)->value('status')==='generating')
            return back()->withErrors(['script'=>'模型正在处理剧本，请完成或重试任务后再修改。']);
        $v=$request->validate([
            'name'=>'required|string|max:191','script'=>'required|string|max:100000',
            'style'=>'required|string|max:191','shot_limit'=>['required',Rule::in([15,30])],
            'script_mode'=>['sometimes',Rule::in(['auto','talking','mixed'])],
        ]);
        $previousMax=DB::table('shots')->where('project_id',$id)->max('duration_seconds')??0;
        if ($previousMax>(int)$v['shot_limit']) return back()->withErrors(['shot_limit'=>'现有镜头超过选择的时长上限，请重新生成分镜。']);
        DB::table('projects')->where('id',$id)->update($v+['updated_at'=>now()]);
        return back()->with('ok','剧本设置已保存');
    }
    public function generate(int $id) {
        $this->project($id);
        $created=DB::transaction(function() use($id) {
            $project=DB::table('projects')->where('id',$id)->lockForUpdate()->first();
            if (!$project) abort(404);
            $running=DB::table('generation_jobs')->where('project_id',$id)
                ->whereIn('status',['queued','running'])->orderByDesc('id')->first();
            if($running) {
                $stale=($running->started_at ?? $running->created_at);
                if($stale && \Illuminate\Support\Carbon::parse($stale)->isAfter(now()->subMinutes(8))) return false;
                DB::table('generation_jobs')->where('id',$running->id)->update([
                    'status'=>'failed','error_message'=>'上一任务超出等待期限，可重新提交。',
                    'finished_at'=>now(),'updated_at'=>now(),
                ]);
            }
            DB::table('generation_jobs')->insert([
                'project_id'=>$id,'status'=>'queued','attempts'=>0,'error_message'=>null,
                'request_script'=>$project->script,'request_style'=>$project->style,
                'request_shot_limit'=>$project->shot_limit,
                'request_mode'=>$project->script_mode??'auto',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('projects')->where('id',$id)->update(['status'=>'generating','updated_at'=>now()]);
            return true;
        });
        return back()->with('ok',$created
            ? '已提交文本模型生成任务。页面会显示处理状态；模型响应较慢时无需重复点击。'
            : '已有生成任务正在处理，请查看页面任务状态。');
    }
    public function generationStatus(int $id) {
        $project=$this->project($id);
        $job=DB::table('generation_jobs')->where('project_id',$id)->orderByDesc('id')->first();
        if($job && $job->status==='running' && $job->started_at &&
           \Illuminate\Support\Carbon::parse($job->started_at)->isBefore(now()->subMinutes(8))) {
            DB::table('generation_jobs')->where('id',$job->id)->where('status','running')->update([
                'status'=>'failed','finished_at'=>now(),
                'error_message'=>'模型响应超时或处理被服务器中断，请检查接口设置后重新生成。',
                'updated_at'=>now(),
            ]);
            DB::table('projects')->where('id',$id)->where('status','generating')->update([
                'status'=>'error','updated_at'=>now(),
            ]);
            $job=DB::table('generation_jobs')->where('id',$job->id)->first();
        }
        return response()->json([
            'project_status'=>$project->status==='generating'&&$job&&$job->status==='failed'?'error':$project->status,
            'status'=>$job?->status??'none',
            'attempts'=>$job?->attempts??0,
            'error'=>$job?->status==='failed'?$job->error_message:null,
            'created_at'=>$job?->created_at,
            'started_at'=>$job?->started_at,
            'finished_at'=>$job?->finished_at,
        ]);
    }
    public function runGeneration(int $id,StoryboardEngine $engine) {
        $this->project($id);
        // Lock/claim exactly one pending job; never run concurrent model calls for one project.
        $job=DB::table('generation_jobs')->where('project_id',$id)
            ->orderByDesc('id')->first();
        if(!$job) return response()->json(['status'=>'none'],404);
        if($job->status!=='queued') return response()->json(['status'=>$job->status],202);
        $claimed=DB::table('generation_jobs')->where('id',$job->id)
            ->where('status','queued')->update([
                'status'=>'running','attempts'=>DB::raw('attempts + 1'),
                'started_at'=>now(),'updated_at'=>now(),
            ]);
        if(!$claimed) return response()->json(['status'=>'running'],202);
        // PHP processing continues if the browser refreshes while waiting.
        ignore_user_abort(true);
        if(function_exists('set_time_limit'))@set_time_limit(360);
        try {
            $engine->setMode((string)($job->request_mode??'auto'));
            $result=$engine->generate($job->request_script,$job->request_style,(int)$job->request_shot_limit);
            DB::transaction(function() use($id,$job,$result) {
                $current=DB::table('generation_jobs')->where('id',$job->id)->lockForUpdate()->first();
                if(!$current || $current->status!=='running')
                    throw new RuntimeException('此生成任务已过期，结果不会覆盖当前分镜。');
                $previous=DB::table('assets')->where('project_id',$id)->get()
                    ->keyBy(static fn($a)=>$a->type.'|'.$a->name);
                DB::table('shots')->where('project_id',$id)->delete();
                DB::table('assets')->where('project_id',$id)->delete();
                foreach ($result['shots'] as $i=>$shot) {
                    DB::table('shots')->insert([
                        'project_id'=>$id,'sequence'=>$i+1,'title'=>$shot['title'],
                        'visual'=>$shot['visual'],'dialogue'=>$shot['dialogue'],
                        'action_before'=>$shot['action_before'],
                        'on_screen_text'=>$shot['on_screen_text']??null,
                        'delivery'=>$shot['delivery']??'on_camera',
                        'duration_seconds'=>$shot['duration_seconds'],'prompt'=>$shot['prompt'],
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
                $included=[];
                foreach($result['assets'] as $asset) {
                    $key=$asset['type'].'|'.$asset['name'];
                    $included[$key]=true;
                    $old=$previous->get($key);
                    if($old&&$old->file_path &&
                        ($old->source_kind==='upload' || ($asset['source_kind']??'generate')==='generate')) {
                        // Never reuse an AI-generated image as a "customer original".
                        $asset['file_path']=$old->file_path;
                        $asset['source_kind']=$old->source_kind==='upload'?'upload':($asset['source_kind']??'generate');
                        $asset['image_status']=$old->image_status;
                    }
                    DB::table('assets')->insert($asset+[
                        'project_id'=>$id,'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
                // Keep uploads even if the new AI response omitted that brand asset.
                foreach($previous as $key=>$old) {
                    if(isset($included[$key])||$old->source_kind!=='upload'||!$old->file_path)continue;
                    DB::table('assets')->insert([
                        'project_id'=>$id,'type'=>$old->type,'name'=>$old->name,
                        'description'=>$old->description,'prompt'=>$old->prompt,
                        'voice_description'=>$old->voice_description,
                        'source_kind'=>'upload','image_status'=>'uploaded',
                        'file_path'=>$old->file_path,'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
                DB::table('generation_jobs')->where('id',$job->id)->update([
                    'status'=>'done','finished_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('projects')->where('id',$id)->update([
                    'status'=>'ready','detected_mode'=>$result['script_mode']??null,
                    'updated_at'=>now(),
                ]);
            });
            return response()->json(['status'=>'done','shots'=>count($result['shots'])]);
        } catch(\Throwable $e) {
            $safe=$e instanceof RuntimeException
                ? mb_substr($e->getMessage(),0,250)
                : '生成过程中发生服务器错误，请检查日志或稍后重试。';
            DB::transaction(function() use($id,$job,$safe) {
                $changed=DB::table('generation_jobs')->where('id',$job->id)
                    ->where('status','running')->update([
                        'status'=>'failed','error_message'=>$safe,
                        'finished_at'=>now(),'updated_at'=>now(),
                    ]);
                if($changed) DB::table('projects')->where('id',$id)->update([
                    'status'=>'error','updated_at'=>now(),
                ]);
            });
            Log::warning('Storyboard generation failed',['project_id'=>$id,'exception'=>get_class($e)]);
            return response()->json(['status'=>'failed','error'=>$safe]);
        }
    }
    public function saveShot(Request $request,int $id,int $shot) {
        $project=$this->project($id);
        if (!DB::table('shots')->where('id',$shot)->where('project_id',$id)->exists()) abort(404);
        $v=$request->validate([
            'title'=>'required|string|max:191','visual'=>'required|string|max:20000',
            'dialogue'=>'nullable|string|max:20000','action_before'=>'nullable|string|max:5000',
            'on_screen_text'=>'nullable|string|max:5000',
            'delivery'=>['sometimes',Rule::in(['on_camera','voiceover','none'])],
            'duration_seconds'=>'required|integer|min:1|max:'.$project->shot_limit,
        ]);
        DB::table('shots')->where('id',$shot)->update($v+['updated_at'=>now()]);
        return back()->with('ok','镜头已保存');
    }
    public function prompt(int $id,int $shot,Request $request,PromptBuilder $builder) {
        $project=$this->project($id);
        $item=DB::table('shots')->where('id',$shot)->where('project_id',$id)->first();
        if (!$item) abort(404);
        $target=$request->query('target','kling');
        return response()->json(['target'=>$target,'prompt'=>$builder->build($project,$item,$target)]);
    }
    public function settings() {
        $rows=DB::table('settings')->where('key','like','ai.%')->pluck('value','key');
        $prompts=[];
        foreach(PromptPresets::LABELS as $kind=>$label) {
            $prompts[$kind]=[
                'label'=>$label,'mode'=>PromptPresets::mode($kind),
                'reference'=>PromptPresets::reference($kind),
                'custom'=>PromptPresets::custom($kind),
                'source'=>PromptPresets::SOURCES[$kind],
                'source_url'=>PromptPresets::LINKS[$kind],
            ];
        }
        return view('settings',[
            'textApiBase'=>$rows['ai.text.api_base']??$rows['ai.api_base']??'https://api.openai.com/v1',
            'textModel'=>$rows['ai.text.model']??$rows['ai.model']??'',
            'textHasKey'=>!empty($rows['ai.text.api_key']??$rows['ai.api_key']??null),
            'imageApiBase'=>$rows['ai.image.api_base']??'https://api.openai.com/v1',
            'imageModel'=>$rows['ai.image.model']??'',
            'imageDelivery'=>$rows['ai.image.delivery']??'chatgpt2api_tasks',
            'imageHasKey'=>!empty($rows['ai.image.api_key']??null),
            'prompts'=>$prompts,
        ]);
    }
    public function savePromptSettings(Request $request) {
        $v=$request->validate([
            'kind'=>['required',Rule::in(array_keys(PromptPresets::LABELS))],
            'mode'=>['required',Rule::in(['reference','custom'])],
            'reference'=>'required|string|max:25000',
            'custom'=>'nullable|string|max:25000',
        ]);
        PromptPresets::save($v['kind'],$v['mode'],$v['reference'],$v['custom']??'');
        return back()->with('ok','已保存「'.PromptPresets::LABELS[$v['kind']].'」提示词，后续生成会使用当前选定模式');
    }
    public function resetPromptSettings(string $kind) {
        PromptPresets::reset($kind);
        return back()->with('ok','已恢复「'.PromptPresets::LABELS[$kind].'」原始参考提示词');
    }
    public function saveSettings(Request $request) {
        $v=$request->validate([
            'kind'=>['required',Rule::in(['text','image'])],
            'api_base'=>'required|url|starts_with:https://|max:250',
            'model'=>'required|string|max:191',
            'api_key'=>'nullable|string|max:2000',
            'delivery'=>['nullable',Rule::in(['chatgpt2api_tasks','openai_sync'])],
        ]);
        $parts=parse_url($v['api_base']);
        $host=strtolower((string)($parts['host']??''));
        if(!$parts||($parts['scheme']??'')!=='https'||!$host||
           isset($parts['user'])||isset($parts['pass'])||
           isset($parts['query'])||isset($parts['fragment'])||
           $host==='localhost'||str_ends_with($host,'.local')||
           filter_var($host,FILTER_VALIDATE_IP))
            return back()->withErrors(['api_base'=>'请填写可信的公网 HTTPS API 地址，不能使用 IP、localhost、账号或 URL 参数']);
        $type=$v['kind'];
        $prefix='ai.'.$type.'.';
        DB::transaction(function() use ($v,$prefix) {
            $fields=['api_base'=>$v['api_base'],'model'=>$v['model']];
            if($v['kind']==='image')$fields['delivery']=$v['delivery']??'chatgpt2api_tasks';
            foreach($fields as $k=>$value)
                DB::table('settings')->updateOrInsert(['key'=>$prefix.$k],[
                    'value'=>$value,'updated_at'=>now(),
                ]);
            if (filled($v['api_key']??null))
                DB::table('settings')->updateOrInsert(['key'=>$prefix.'api_key'],[
                    'value'=>Crypt::encryptString($v['api_key']),'updated_at'=>now(),
                ]);
        });
        return back()->with('ok',($type==='text'?'文本':'图片').'模型配置已保存。API Key 仅存储加密值，未输入新密钥则保留旧值。');
    }
    public function export(int $id) {
        $project=$this->project($id);
        $shots=DB::table('shots')->where('project_id',$id)->orderBy('sequence')->get();
        $assets=DB::table('assets')->where('project_id',$id)->get();
        $json=json_encode(['project'=>$project,'shots'=>$shots,'assets'=>$assets],
            JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
        return response($json,200,['Content-Type'=>'application/json; charset=utf-8',
            'Content-Disposition'=>'attachment; filename="aidrama-project-'.$id.'.json"']);
    }
}
