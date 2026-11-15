<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('projects',function(Blueprint $t){
            $t->bigIncrements('id');
            $t->string('name',191);
            $t->longText('script')->nullable();
            $t->string('style',191)->default('3D国风动画');
            $t->unsignedTinyInteger('shot_limit')->default(15);
            $t->string('status',24)->default('draft');
            $t->timestamps();
        });
        Schema::create('shots',function(Blueprint $t){
            $t->bigIncrements('id');
            $t->unsignedBigInteger('project_id');
            $t->unsignedInteger('sequence');
            $t->string('title',191);
            $t->longText('visual');
            $t->longText('dialogue')->nullable();
            $t->text('action_before')->nullable();
            $t->unsignedTinyInteger('duration_seconds');
            $t->longText('prompt')->nullable();
            $t->timestamps();
            $t->unique(['project_id','sequence']);
            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
        Schema::create('assets',function(Blueprint $t){
            $t->bigIncrements('id'); $t->unsignedBigInteger('project_id');
            $t->string('type',24); $t->string('name',191);
            $t->longText('description')->nullable();
            $t->longText('prompt')->nullable();
            $t->string('file_path',512)->nullable();
            $t->text('voice_description')->nullable();
            $t->timestamps();
            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
        Schema::create('settings',function(Blueprint $t){
            $t->string('key',100)->primary(); $t->longText('value')->nullable(); $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('settings'); Schema::dropIfExists('assets');
        Schema::dropIfExists('shots'); Schema::dropIfExists('projects');
    }
};
