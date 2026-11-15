<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StudioController;
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
    Route::post('/projects/{id}/generate',[StudioController::class,'generate'])->whereNumber('id')->name('project.generate');
    Route::get('/projects/{id}/generation/status',[StudioController::class,'generationStatus'])->whereNumber('id')->name('generation.status');
    Route::post('/projects/{id}/generation/run',[StudioController::class,'runGeneration'])->middleware('throttle:10,1')->whereNumber('id')->name('generation.run');
    Route::post('/projects/{id}/shots/{shot}',[StudioController::class,'saveShot'])->whereNumber('id')->whereNumber('shot')->name('shot.save');
    Route::get('/projects/{id}/shots/{shot}/prompt',[StudioController::class,'prompt'])->whereNumber('id')->whereNumber('shot')->name('shot.prompt');
    Route::get('/projects/{id}/export',[StudioController::class,'export'])->whereNumber('id')->name('project.export');
    Route::get('/settings',[StudioController::class,'settings'])->name('settings');
    Route::post('/settings',[StudioController::class,'saveSettings'])->name('settings.save');
});
