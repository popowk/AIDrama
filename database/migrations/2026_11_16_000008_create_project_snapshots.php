<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        if(Schema::hasTable('project_snapshots'))return;
        Schema::create('project_snapshots',static function(Blueprint $t){
            $t->bigIncrements('id');
            $t->unsignedBigInteger('project_id');
            $t->string('reason',32);
            $t->unsignedBigInteger('source_job_id')->nullable();
            $t->unsignedInteger('shot_count')->default(0);
            $t->unsignedInteger('asset_count')->default(0);
            $t->longText('payload');
            $t->timestamps();
            $t->index(['project_id','id']);
            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }
    public function down():void
    {
        Schema::dropIfExists('project_snapshots');
    }
};
