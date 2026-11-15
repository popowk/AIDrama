<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('generation_jobs',function(Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('project_id');
            $t->string('status',20)->default('queued');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->longText('request_script');
            $t->string('request_style',191);
            $t->unsignedTinyInteger('request_shot_limit');
            $t->text('error_message')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['project_id','status']);
            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('generation_jobs'); }
};
