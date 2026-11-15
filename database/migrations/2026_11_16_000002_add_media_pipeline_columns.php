<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('generation_jobs',function(Blueprint $t) {
            $t->string('request_mode',16)->default('auto');
        });
        Schema::table('projects',function(Blueprint $t) {
            $t->string('script_mode',16)->default('auto');
            $t->string('detected_mode',16)->nullable();
        });
        Schema::table('shots',function(Blueprint $t) {
            $t->text('on_screen_text')->nullable();
            $t->string('delivery',16)->default('on_camera');
        });
        Schema::table('assets',function(Blueprint $t) {
            $t->string('source_kind',16)->default('generate');
            $t->string('image_status',16)->default('pending');
            $t->timestamp('image_started_at')->nullable();
            $t->text('image_error')->nullable();
        });
    }
    public function down(): void {
        Schema::table('generation_jobs',function(Blueprint $t) {
            $t->dropColumn('request_mode');
        });
        Schema::table('assets',function(Blueprint $t) {
            $t->dropColumn(['source_kind','image_status','image_started_at','image_error']);
        });
        Schema::table('shots',function(Blueprint $t) {
            $t->dropColumn(['on_screen_text','delivery']);
        });
        Schema::table('projects',function(Blueprint $t) {
            $t->dropColumn(['script_mode','detected_mode']);
        });
    }
};
