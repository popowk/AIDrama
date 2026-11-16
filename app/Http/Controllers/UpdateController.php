<?php
namespace App\Http\Controllers;

use App\Services\ReleaseManager;
use App\Services\LibraryUpdater;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use RuntimeException;

class UpdateController
{
    public function index(ReleaseManager $manager): View {
        try {$current=$manager->current();}
        catch(\Throwable $e){$current='unknown';}
        $libraries=new LibraryUpdater();
        return view('updates',[
            'current'=>$current,
            'libraryCurrent'=>[
                'styles'=>$libraries->current('styles'),
                'skills'=>$libraries->current('skills'),
            ],
        ]);
    }
    public function checkLibraries(Request $request,LibraryUpdater $library):JsonResponse
    {
        $kind=(string)$request->query('kind','');
        if($kind!==''&&!in_array($kind,['styles','skills'],true))
            return response()->json(['error'=>'不支持的素材库类型'],422);
        try{return response()->json($library->check($kind!==''?$kind:null));}
        catch(\Throwable $e){return response()->json(['error'=>'素材库版本检查失败'],503);}
    }

    public function applyLibrary(Request $request,LibraryUpdater $library):JsonResponse
    {
        $v=$request->validate([
            'kind'=>['required',\Illuminate\Validation\Rule::in(['styles','skills'])],
        ]);
        try{return response()->json($library->apply($v['kind']));}
        catch(\Throwable $e){
            return response()->json([
                'status'=>'failed',
                'error'=>$e instanceof RuntimeException?$e->getMessage():'素材库更新失败，请检查服务器私有日志',
            ],422);
        }
    }
    public function check(ReleaseManager $manager): JsonResponse {
        try {return response()->json($manager->check());}
        catch(\Throwable $e) {
            return response()->json(['error'=>$e instanceof RuntimeException?$e->getMessage():'检查更新失败'],503);
        }
    }
    public function status(ReleaseManager $manager): JsonResponse {
        try {return response()->json($manager->status());}
        catch(\Throwable $e){return response()->json(['error'=>'无法读取更新状态'],503);}
    }
    public function apply(ReleaseManager $manager): JsonResponse {
        try {return response()->json($manager->apply());}
        catch(\Throwable $e) {
            return response()->json(['status'=>'failed','error'=>$e instanceof RuntimeException?$e->getMessage():'更新失败，请检查服务器日志'],422);
        }
    }
}
