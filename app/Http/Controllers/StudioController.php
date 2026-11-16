<?php
namespace App\Http\Controllers;

use App\Services\StoryboardEngine;
use App\Services\PromptBuilder;
use App\Services\PromptPresets;
use App\Services\CreativeLibrary;
use App\Services\FrameProfile;
use App\Services\StoryboardTable;
use App\Services\ScriptExpansionEngine;
use Illuminate\Validation\ValidationException;
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
        $stylePresets=CreativeLibrary::styles();
        $creativeSkills=CreativeLibrary::skills();
        return view('dashboard',compact('projects','stylePresets','creativeSkills'));
    }
    public function create(Request $request) {
        $v=$request->validate([
            'name'=>'required|string|max:191','script'=>'nullable|string|max:100000',
            'input_kind'=>['sometimes',Rule::in(['script','storyboard'])],
            'storyboard_source'=>'nullable|string|max:100000',
            'style'=>'nullable|string|max:191','shot_limit'=>['required',Rule::in([15,30])],
            'script_mode'=>['sometimes',Rule::in(['auto','talking','mixed'])],
            'frame_ratio'=>['sometimes',Rule::in(['landscape','portrait'])],
            'style_preset'=>['sometimes',Rule::in(array_keys(CreativeLibrary::styles()))],
            'skills_present'=>'sometimes|accepted',
            'skill_keys'=>'sometimes|array|max:8',
            'skill_keys.*'=>['string',Rule::in(array_keys(CreativeLibrary::skills()))],
        ]);
        $kind=$v['input_kind']??'script';
        $this->validateSource($kind,(string)($v['script']??''),(string)($v['storyboard_source']??''));
        $preset=$v['style_preset']??(isset($v['style'])?'custom':'guofeng_3d');
        $style=$preset==='custom'
            ?trim((string)($v['style']??''))
            :(string)(CreativeLibrary::styles()[$preset]['label']??'');
        if($style==='')
            throw \Illuminate\Validation\ValidationException::withMessages([
                'style'=>'选择自定义风格时，请填写画风名称。',
            ]);
        $id=DB::table('projects')->insertGetId([
            'name'=>$v['name'],'script'=>$v['script']??'','style'=>$style,
            'input_kind'=>$kind,'storyboard_source'=>$v['storyboard_source']??null,
            'shot_limit'=>(int)$v['shot_limit'],
            'script_mode'=>$v['script_mode']??'auto','status'=>'draft',
            'frame_ratio'=>$v['frame_ratio']??'landscape',
            'style_preset'=>$preset,
            'creative_skills'=>json_encode(CreativeLibrary::skillIds($v['skill_keys']??[])),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return redirect()->route('project.show',$id)->with('ok','项目已建立');
    }
    private function validateSource(string $kind,string $script,string $storyboard):void {
        if($kind==='storyboard') {
            try { StoryboardTable::parse($storyboard); }
            catch(RuntimeException $e) {
                throw ValidationException::withMessages(['storyboard_source'=>$e->getMessage()]);
            }
        } elseif(trim($script)==='') {
            throw ValidationException::withMessages(['script'=>'请填写完整剧本，或选择“分镜表格”输入方式。']);
        }
    }
    private function project(int $id): object {
        return DB::table('projects')->where('id',$id)->first() ?? abort(404);
    }
    public function show(int $id) {
        $project=$this->project($id);
        $shots=DB::table('shots')->where('project_id',$id)->orderBy('sequence')->get();
        $assets=DB::table('assets')->where('project_id',$id)->orderBy('type')->orderBy('id')->get();
        $total=$shots->sum('duration_seconds');
        $stylePresets=CreativeLibrary::styles();
        $creativeSkills=CreativeLibrary::skills();
        $selectedSkills=CreativeLibrary::skillIds($project->creative_skills??null);
        $scriptSegments=json_decode((string)($project->script_segments??'[]'),true)?:[];
        return view('project',compact('project','shots','assets','total','stylePresets','creativeSkills','selectedSkills','scriptSegments'));
    }
    public function update(Request $request,int $id) {
        $project=$this->project($id);
        if(DB::table('projects')->where('id',$id)->value('status')==='generating')
            return back()->withErrors(['script'=>'模型正在处理剧本，请完成或重试任务后再修改。']);
        $v=$request->validate([
            'name'=>'required|string|max:191','script'=>'nullable|string|max:100000',
            'input_kind'=>['sometimes',Rule::in(['script','storyboard'])],
            'storyboard_source'=>'nullable|string|max:100000',
            'style'=>'nullable|string|max:191','shot_limit'=>['required',Rule::in([15,30])],
            'script_mode'=>['sometimes',Rule::in(['auto','talking','mixed'])],
            'frame_ratio'=>['sometimes',Rule::in(['landscape','portrait'])],
            'style_preset'=>['sometimes',Rule::in(array_keys(CreativeLibrary::styles()))],
            'skills_present'=>'sometimes|accepted',
            'skill_keys'=>'sometimes|array|max:8',
            'skill_keys.*'=>['string',Rule::in(array_keys(CreativeLibrary::skills()))],
        ]);
        $kind=$v['input_kind']??($project->input_kind??'script');
        $source=(string)($v['storyboard_source']??'');
        $this->validateSource($kind,(string)($v['script']??''),$source);
        if($kind==='storyboard' && trim($source)!==trim((string)($project->storyboard_source??''))) {
            // Source changed: never treat an old expansion as the new source.
            $v['script']='';
            $v['script_segments']=null;
        } elseif((string)($v['script']??'')!==(string)($project->script??'')
            || $kind!==($project->input_kind??'script')) {
            // A manually edited merged script supersedes stale segment cards.
            $v['script_segments']=null;
        }
        $v['input_kind']=$kind;
        $preset=$v['style_preset']??($project->style_preset??'custom');
        $style=$preset==='custom'
            ?trim((string)($v['style']??$project->style))
            :(string)(CreativeLibrary::styles()[$preset]['label']??'');
        if($style==='')
            throw \Illuminate\Validation\ValidationException::withMessages([
                'style'=>'选择自定义风格时，请填写画风名称。',
            ]);
        $v['style']=$style;
        $v['style_preset']=$preset;
        $previousMax=DB::table('shots')->where('project_id',$id)->max('duration_seconds')??0;
        if ($previousMax>(int)$v['shot_limit']) return back()->withErrors(['shot_limit'=>'现有镜头超过选择的时长上限，请重新生成分镜。']);
        if(isset($v['skills_present']))
            $v['creative_skills']=json_encode(CreativeLibrary::skillIds($v['skill_keys']??[]));
        unset($v['skills_present'],$v['skill_keys']);
        DB::table('projects')->where('id',$id)->update($v+['updated_at'=>now()]);
        return redirect()->to(route('project.show',$id).'#script-editor')->with('ok','剧本设置已保存');
    }
    public function generate(int $id) {
        $project=$this->project($id);
        if(trim((string)$project->script)==='')
            return redirect()->to(route('project.show',$id).'#script-editor')
                ->withErrors(['script'=>'请先根据分镜表格扩写剧本，或输入完整剧本并保存。']);
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
                'request_ratio'=>$project->frame_ratio??'landscape',
                'request_style_preset'=>$project->style_preset??'custom',
                'request_skills'=>$project->creative_skills??null,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('projects')->where('id',$id)->update(['status'=>'generating','updated_at'=>now()]);
            return true;
        });
        // Use a stable fragment to defeat browser scroll restoration to the
        // previous large asset preview after a POST/redirect/reload cycle.
        return redirect()->to(route('project.show',$id).'#generation-state')
            ->with('ok',$created
                ? '已提交文本模型生成任务。页面会显示处理状态；模型响应较慢时无需重复点击。'
                : '已有生成任务正在处理，请查看页面任务状态。');
    }
    public function expandStoryboard(int $id) {
        $project=$this->project($id);
        if(($project->input_kind??'script')!=='storyboard')
            return redirect()->to(route('project.show',$id).'#script-editor')
                ->withErrors(['storyboard_source'=>'请先选择“分镜表格”并保存。']);
        try { StoryboardTable::parse((string)$project->storyboard_source); }
        catch(RuntimeException $e) {
            return redirect()->to(route('project.show',$id).'#script-editor')
                ->withErrors(['storyboard_source'=>$e->getMessage()]);
        }
        $created=DB::transaction(function()use($id){
            $p=DB::table('projects')->where('id',$id)->lockForUpdate()->first();
            $running=DB::table('generation_jobs')->where('project_id',$id)
                ->whereIn('status',['queued','running'])->orderByDesc('id')->first();
            if($running) {
                $stale=$running->started_at??$running->created_at;
                if($stale && \Illuminate\Support\Carbon::parse($stale)->isAfter(now()->subMinutes(8)))return false;
                DB::table('generation_jobs')->where('id',$running->id)->update([
                    'status'=>'failed','error_message'=>'上一任务超时，可重新提交。',
                    'finished_at'=>now(),'updated_at'=>now(),
                ]);
            }
            DB::table('generation_jobs')->insert([
                'project_id'=>$id,'status'=>'queued','kind'=>'expand',
                'attempts'=>0,'request_script'=>$p->script??'',
                'request_storyboard'=>$p->storyboard_source,
                'request_style'=>$p->style,'request_shot_limit'=>$p->shot_limit,
                'request_mode'=>$p->script_mode??'auto',
                'request_ratio'=>$p->frame_ratio??'landscape',
                'request_style_preset'=>$p->style_preset??'custom',
                'request_skills'=>$p->creative_skills??null,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('projects')->where('id',$id)->update(['status'=>'generating','updated_at'=>now()]);
            return true;
        });
        return redirect()->to(route('project.show',$id).'#generation-state')
            ->with('ok',$created?'已开始逐镜扩写剧本，生成后将返回剧本编辑区。':'已有生成任务正在处理。');
    }

    public function saveSegments(Request $request,int $id) {
        $project=$this->project($id);
        if($project->status==='generating')
            return redirect()->to(route('project.show',$id).'#script-editor')
                ->withErrors(['script'=>'生成期间不能编辑分段剧本。']);
        $segments=json_decode((string)($project->script_segments??'[]'),true)?:[];
        if(!$segments)abort(404);
        $v=$request->validate([
            'parts'=>'required|array|size:'.count($segments),
            'parts.*'=>'required|string|max:20000',
        ]);
        foreach($segments as $i=>&$segment)
            $segment['script']=trim((string)($v['parts'][$i]??''));
        unset($segment);
        $combined=StoryboardTable::combine($segments);
        if(mb_strlen($combined)>100000)
            return redirect()->to(route('project.show',$id).'#script-segments')
                ->withErrors(['parts'=>'完整剧本超过 100000 字符，请缩短分段剧本。']);
        DB::table('projects')->where('id',$id)->update([
            'script'=>$combined,
            'script_segments'=>json_encode($segments,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'updated_at'=>now(),
        ]);
        return redirect()->to(route('project.show',$id).'#script-segments')->with('ok','逐镜剧本已保存并同步到完整剧本');
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
            'kind'=>$job?->kind??'shots',
            'attempts'=>$job?->attempts??0,
            'error'=>$job?->status==='failed'?$job->error_message:null,
            'created_at'=>$job?->created_at,
            'started_at'=>$job?->started_at,
            'finished_at'=>$job?->finished_at,
        ]);
    }
    public function runGeneration(int $id,StoryboardEngine $engine,?ScriptExpansionEngine $expander=null) {
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
            if(($job->kind??'shots')==='expand') {
                $expander ??= new ScriptExpansionEngine();
                $segments=$expander->expand(
                    (string)($job->request_storyboard??''),
                    (string)$job->request_style,(int)$job->request_shot_limit,
                    (string)($job->request_ratio??'landscape'),
                    (string)($job->request_style_preset??'custom'),
                    $job->request_skills??null
                );
                DB::transaction(function()use($id,$job,$segments){
                    $current=DB::table('generation_jobs')->where('id',$job->id)->lockForUpdate()->first();
                    if(!$current||$current->status!=='running')
                        throw new RuntimeException('扩写任务已过期，结果不会覆盖当前剧本。');
                    DB::table('projects')->where('id',$id)->update([
                        'script'=>StoryboardTable::combine($segments),
                        'script_segments'=>json_encode($segments,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                        'status'=>DB::table('shots')->where('project_id',$id)->exists()?'ready':'draft',
                        'updated_at'=>now(),
                    ]);
                    DB::table('generation_jobs')->where('id',$job->id)->update([
                        'status'=>'done','finished_at'=>now(),'updated_at'=>now(),
                    ]);
                });
                return response()->json(['status'=>'done','kind'=>'expand','segments'=>count($segments)]);
            }
            $engine->setMode((string)($job->request_mode??'auto'));
            $engine->setCreativeContext((string)($job->request_ratio??'landscape'),
                (string)($job->request_style_preset??'custom'),
                (string)($job->request_skills??''));
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
                    if($old)$asset['frame_ratio']=$old->frame_ratio??'inherit';
                    if($old &&
                        ($old->file_path || ($old->image_task_id && $old->image_status==='generating')) &&
                        ($old->source_kind==='upload' || ($asset['source_kind']??'generate')==='generate')) {
                        // Reuse authentic uploaded assets and recover still-running
                        // remote image tasks after a storyboard regeneration.
                        // Never treat an AI output as a customer original.
                        $asset['file_path']=$old->file_path;
                        $asset['source_kind']=$old->source_kind==='upload'?'upload':($asset['source_kind']??'generate');
                        $asset['image_status']=$old->image_status;
                        foreach(['image_task_id','image_remote_status','image_started_at',
                                 'image_poll_after','image_resume_attempted','image_error'] as $field)
                            $asset[$field]=$old->$field;
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
                        'frame_ratio'=>$old->frame_ratio??'inherit',
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
        return redirect()->to(route('project.show',$id).'#shot-'.$shot)->with('ok','镜头已保存');
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
    public function detectModels(Request $request,\App\Services\ModelDiscovery $discovery)
    {
        $v=$request->validate([
            'kind'=>['required',Rule::in(['text','image'])],
            'api_base'=>'required|url|starts_with:https://|max:250',
            'api_key'=>'nullable|string|max:2000',
        ]);
        try{
            return response()->json(
                $discovery->discover($v['kind'],$v['api_base'],$v['api_key']??null)
            );
        }catch(\Throwable $e){
            return response()->json(['error'=>$e instanceof RuntimeException
                ?$e->getMessage():'模型列表检测出现服务器错误，请检查私有日志'],422);
        }
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
