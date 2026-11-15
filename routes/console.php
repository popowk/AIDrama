<?php
use Illuminate\Support\Facades\Artisan;
Artisan::command('aidrama:set-password',function () {
    $one=$this->secret('设置管理员密码（至少12位）');
    $two=$this->secret('再次输入管理员密码');
    if (!is_string($one) || strlen($one)<12 || $one!==$two) {
        $this->error('密码太短或两次输入不一致'); return 1;
    }
    $dir=storage_path('app/private');
    if (!is_dir($dir) && !mkdir($dir,0700,true)) { $this->error('无法写入私有存储');return 1; }
    $file=$dir.'/admin.hash';
    if (file_put_contents($file,password_hash($one,PASSWORD_DEFAULT),LOCK_EX)===false) {
        $this->error('写入失败'); return 1;
    }
    chmod($file,0600);
    $this->info('管理员密码已设置，且不会写入 GitHub'); return 0;
})->purpose('为单机版设置管理员登录密码');
