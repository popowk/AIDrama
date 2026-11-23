<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\AssetController;
use App\Http\Middleware\RequireAdmin;

Route::get('/login',function() {
    return view('login',['configured'=>is_file(storage_path('app/private/admin.hash'))]);
})->name('login');
Route::post('/login',function(Request $request) {
    $request->validate(['password'=>'required|string']);
    $hash=@file_get_contents(storage_path('app/private/admin.hash'));
    $stored=is_string($hash)?trim($hash):'';
    $candidate=(string)$request->input('password');
    $valid=str_starts_with($stored,'sha256$')
        ? password_verify(hash('sha256',$candidate),substr($stored,7))
        : ($stored!=='' && password_verify($candidate,$stored));
    if (!$valid) {
        return back()->withErrors(['password'=>'密码错误，或尚未通过命令行设置管理员密码']);
    }
    $request->session()->regenerate();
    $request->session()->put('aidrama.admin',true);
    return redirect()->route('home');
})->middleware('throttle:5,1')->name('login.submit');
Route::middleware(RequireAdmin::class)->group(function() {
    Route::post('/logout',function(Request $request) {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    })->name('logout');
    Route::get('/',[StudioController::class,'index'])->name('home');
    Route::post('/projects',[StudioController::class,'create'])->name('project.create');
    Route::get('/projects/{id}',[StudioController::class,'show'])->whereNumber('id')->name('project.show');
    Route::post('/projects/{id}/save',[StudioController::class,'update'])->whereNumber('id')->name('project.update');
    Route::post('/projects/{id}/storyboard/preview',[StudioController::class,'previewStoryboard'])->middleware('throttle:75,1')->whereNumber('id')->name('project.storyboard.preview');
    Route::post('/projects/{id}/generate',[StudioController::class,'generate'])->whereNumber('id')->name('project.generate');
    Route::post('/projects/{id}/expand-storyboard',[StudioController::class,'expandStoryboard'])->whereNumber('id')->name('project.expand');
    Route::post('/projects/{id}/script-segments',[StudioController::class,'saveSegments'])->whereNumber('id')->name('project.segments.save');
    Route::get('/projects/{id}/generation/status',[StudioController::class,'generationStatus'])->whereNumber('id')->name('generation.status');
    Route::post('/projects/{id}/generation/retry',[StudioController::class,'retryGeneration'])
        ->middleware('throttle:6,1')->whereNumber('id')->name('generation.retry');
    Route::post('/projects/{id}/snapshots/{snapshot}/restore',[StudioController::class,'restoreSnapshot'])
        ->middleware('throttle:4,1')->whereNumber('id')->whereNumber('snapshot')->name('project.snapshot.restore');
    Route::post('/projects/{id}/generation/run',[StudioController::class,'runGeneration'])->middleware('throttle:10,1')->whereNumber('id')->name('generation.run');
    Route::post('/projects/{id}/shots/{shot}',[StudioController::class,'saveShot'])->whereNumber('id')->whereNumber('shot')->name('shot.save');
    Route::post('/projects/{id}/shots/{shot}/assets',[StudioController::class,'saveShotAssets'])
        ->middleware('throttle:90,1')->whereNumber('id')->whereNumber('shot')->name('shot.assets.save');
    Route::post('/projects/{id}/shots/{shot}/assets/recognize',[StudioController::class,'recognizeShotAssets'])
        ->middleware('throttle:8,1')->whereNumber('id')->whereNumber('shot')->name('shot.assets.recognize');
    Route::post('/projects/{id}/shots/{shot}/prompt',[StudioController::class,'prompt'])->middleware('throttle:12,1')->whereNumber('id')->whereNumber('shot')->name('shot.prompt');
    Route::get('/projects/{id}/export',[StudioController::class,'export'])->whereNumber('id')->name('project.export');
    Route::get('/updates',[UpdateController::class,'index'])->name('updates.index');
    Route::get('/updates/check',[UpdateController::class,'check'])->middleware('throttle:12,1')->name('updates.check');
    Route::get('/updates/libraries/check',[UpdateController::class,'checkLibraries'])
        ->middleware('throttle:12,1')->name('updates.libraries.check');
    Route::post('/updates/libraries/apply',[UpdateController::class,'applyLibrary'])
        ->middleware('throttle:8,1')->name('updates.libraries.apply');
    Route::get('/updates/status',[UpdateController::class,'status'])->name('updates.status');
    Route::post('/updates/apply',[UpdateController::class,'apply'])->middleware('throttle:3,1')->name('updates.apply');
    Route::get('/settings',[StudioController::class,'settings'])->name('settings');
    Route::post('/settings/models/detect',[StudioController::class,'detectModels'])
        ->middleware('throttle:12,1')->name('settings.models.detect');
    Route::post('/settings',[StudioController::class,'saveSettings'])->name('settings.save');
    Route::post('/settings/prompts',[StudioController::class,'savePromptSettings'])->name('settings.prompts.save');
    Route::post('/settings/prompts/{kind}/reset',[StudioController::class,'resetPromptSettings'])
        ->where('kind','script|image|image_character|image_scene|image_prop|kling|seedance')->name('settings.prompts.reset');
    Route::post('/projects/{id}/assets/{asset}/archive',[AssetController::class,'archive'])
        ->whereNumber('id')->whereNumber('asset')->name('asset.archive');
    Route::post('/projects/{id}/assets/{asset}/restore',[AssetController::class,'restore'])
        ->whereNumber('id')->whereNumber('asset')->name('asset.restore');
    Route::post('/projects/{id}/assets/{asset}/prompt',[AssetController::class,'savePrompt'])
        ->whereNumber('id')->whereNumber('asset')->name('asset.prompt.save');
    Route::post('/projects/{id}/assets/{asset}/prompt/reset',[AssetController::class,'resetPrompt'])
        ->whereNumber('id')->whereNumber('asset')->name('asset.prompt.reset');
    Route::post('/projects/{id}/assets/{asset}/frame-ratio',[AssetController::class,'frameRatio'])
        ->whereNumber('id')->whereNumber('asset')->name('asset.frameRatio');
    Route::post('/projects/{id}/assets/{asset}/upload',[AssetController::class,'upload'])
        ->whereNumber('id')->whereNumber('asset')->name('asset.upload');
    Route::post('/projects/{id}/assets/{asset}/generate',[AssetController::class,'generate'])
        ->middleware('throttle:20,1')->whereNumber('id')->whereNumber('asset')->name('asset.generate');
    Route::get('/projects/{id}/assets/{asset}/status',[AssetController::class,'status'])
        ->whereNumber('id')->whereNumber('asset')->name('asset.status');
    Route::get('/projects/{id}/assets/{asset}/file',[AssetController::class,'file'])
        ->whereNumber('id')->whereNumber('asset')->name('asset.file');
    Route::get('/projects/{id}/assets/{asset}/thumbnail',[AssetController::class,'thumbnail'])
        ->whereNumber('id')->whereNumber('asset')->name('asset.thumbnail');
    Route::get('/projects/{id}/assets/{asset}/download',[AssetController::class,'download'])
        ->whereNumber('id')->whereNumber('asset')->name('asset.download');
});
