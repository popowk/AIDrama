<?php
namespace App\Http\Controllers;

use App\Services\ReleaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use RuntimeException;

class UpdateController
{
    public function index(ReleaseManager $manager): View {
        try {$current=$manager->current();}
        catch(\Throwable $e){$current='unknown';}
        return view('updates',['current'=>$current]);
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
