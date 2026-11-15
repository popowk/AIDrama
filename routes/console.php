<?php
use Illuminate\Support\Facades\Artisan;
Artisan::command('aidrama:set-password',function () {
    $one=$this->secret('设置管理员密码');
    $two=$this->secret('再次输入管理员密码');
    if (!is_string($one) || $one==='' || $one!==$two) {
        $this->error('密码不能为空，且两次输入必须一致'); return 1;
    }
    $dir=storage_path('app/private');
    if (!is_dir($dir) && !mkdir($dir,0700,true)) { $this->error('无法写入私有存储');return 1; }
    $file=$dir.'/admin.hash';
    if (file_put_contents($file,'sha256$'.password_hash(hash('sha256',$one),PASSWORD_DEFAULT),LOCK_EX)===false) {
        $this->error('写入失败'); return 1;
    }
    chmod($file,0600);
    $this->info('管理员密码已设置，且不会写入 GitHub'); return 0;
})->purpose('为单机版设置管理员登录密码');
