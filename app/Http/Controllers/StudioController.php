<?php
namespace App\Http\Controllers;

use App\Services\StoryboardEngine;
use App\Services\PromptBuilder;
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
        ]);
        $id=DB::table('projects')->insertGetId([
            'name'=>$v['name'],'script'=>$v['script'],'style'=>$v['style'],
            'shot_limit'=>(int)$v['shot_limit'],'status'=>'draft',
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
        $v=$request->validate([
            'name'=>'required|string|max:191','script'=>'required|string|max:100000',
            'style'=>'required|string|max:191','shot_limit'=>['required',Rule::in([15,30])],
        ]);
        $previousMax=DB::table('shots')->where('project_id',$id)->max('duration_seconds')??0;
        if ($previousMax>(int)$v['shot_limit']) return back()->withErrors(['shot_limit'=>'现有镜头超过选择的时长上限，请重新生成分镜。']);
        DB::table('projects')->where('id',$id)->update($v+['updated_at'=>now()]);
        return back()->with('ok','剧本设置已保存');
    }
    public function generate(int $id,StoryBoardEngine $engine) {
        $project=$this->project($id);
        if ($project->status==='generating') return back()->withErrors(['generate'=>'已经有生成任务正在执行']);
        DB::table('projects')->where('id',$id)->update(['status'=>'generating','updated_at'=>now()]);
        try {
            $result=$engine->generate($project->script,$project->style,(int)$project->shot_limit);
            DB::transaction(function() use($id,$result) {
                DB::table('shots')->where('project_id',$id)->delete();
                DB::table('assets')->where('project_id',$id)->delete();
                foreach ($result['shots'] as $i=>$s) {
                    DB::table('shots')->insert([
                        'project_id'=>$id,'sequence'=>$i+1,'title'=>$s['title'],'visual'=>$s['visual'],
                        'dialogue'=>$s['dialogue'],'action_before'=>$s['action_before'],
                        'duration_seconds'=>$s['duration_seconds'],'prompt'=>$s['prompt'],
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
                foreach ($result['assets'] as $a) {
                    DB::table('assets')->insert($a+[
                        'project_id'=>$id,'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            });
            DB::table('projects')->where('id',$id)->update(['status'=>'ready','updated_at'=>now()]);
            return back()->with('ok','已生成 '.count($result['shots']).' 个镜头和 '.count($result['assets']).' 项资产');
        } catch(\Throwable $e) {
            DB::table('projects')->where('id',$id)->update(['status'=>'error','updated_at'=>now()]);
            Log::warning('Storyboard generation failed',['project_id'=>$id,'exception'=>get_class($e)]);
            return back()->withErrors(['generate'=>$e instanceof RuntimeException?$e->getMessage():'生成失败，请检查服务器日志']);
        }
    }
    public function saveShot(Request $request,int $id,int $shot) {
        $project=$this->project($id);
        if (!DB::table('shots')->where('id',$shot)->where('project_id',$id)->exists()) abort(404);
        $v=$request->validate([
            'title'=>'required|string|max:191','visual'=>'required|string|max:20000',
            'dialogue'=>'nullable|string|max:20000','action_before'=>'nullable|string|max:5000',
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
        return view('settings',[
            'textApiBase'=>$rows['ai.text.api_base']??$rows['ai.api_base']??'https://api.openai.com/v1',
            'textModel'=>$rows['ai.text.model']??$rows['ai.model']??'',
            'textHasKey'=>!empty($rows['ai.text.api_key']??$rows['ai.api_key']??null),
            'imageApiBase'=>$rows['ai.image.api_base']??'https://api.openai.com/v1',
            'imageModel'=>$rows['ai.image.model']??'',
            'imageHasKey'=>!empty($rows['ai.image.api_key']??null),
        ]);
    }
    public function saveSettings(Request $request) {
        $v=$request->validate([
            'kind'=>['required',Rule::in(['text','image'])],
            'api_base'=>'required|url|starts_with:https://|max:250',
            'model'=>'required|string|max:191',
            'api_key'=>'nullable|string|max:2000',
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
            foreach(['api_base'=>$v['api_base'],'model'=>$v['model']] as $k=>$value)
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
