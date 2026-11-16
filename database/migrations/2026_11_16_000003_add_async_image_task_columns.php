<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runs in the OLD release during web upgrades. Keep this self-contained and
 * idempotent because MySQL 5.7 DDL cannot be rolled back after partial failure.
 */
return new class extends Migration {
    public function up():void
    {
        if(!Schema::hasTable('assets'))throw new RuntimeException('缺少 assets 表');
        foreach([
            'image_task_id'=>static fn(Blueprint $t)=>$t->string('image_task_id',96)->nullable(),
            'image_remote_status'=>static fn(Blueprint $t)=>$t->string('image_remote_status',24)->nullable(),
            'image_poll_after'=>static fn(Blueprint $t)=>$t->timestamp('image_poll_after')->nullable(),
            'image_resume_attempted'=>static fn(Blueprint $t)=>$t->boolean('image_resume_attempted')->default(false),
        ] as $column=>$make) {
            if(!Schema::hasColumn('assets',$column))
                Schema::table('assets',static function(Blueprint $t) use($make) {$make($t);});
        }
    }
    public function down():void
    {
        if(!Schema::hasTable('assets'))return;
        foreach(['image_task_id','image_remote_status','image_poll_after','image_resume_attempted'] as $column)
            if(Schema::hasColumn('assets',$column))
                Schema::table('assets',static function(Blueprint $t) use($column) {$t->dropColumn($column);});
    }
};
