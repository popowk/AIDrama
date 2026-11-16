<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        // Column-by-column checks make failed partial MySQL 5.7 upgrades retryable.
        foreach([
            'video_mode'=>static fn(Blueprint $t)=>$t->string('video_mode',16)->default('short'),
            'allow_background_music'=>static fn(Blueprint $t)=>$t->boolean('allow_background_music')->default(false),
        ] as $key=>$add) {
            if(!Schema::hasColumn('projects',$key))
                Schema::table('projects',static function(Blueprint $t)use($add){$add($t);});
        }
        foreach([
            'request_video_mode'=>static fn(Blueprint $t)=>$t->string('request_video_mode',16)->default('short'),
            'request_background_music'=>static fn(Blueprint $t)=>$t->boolean('request_background_music')->default(false),
        ] as $key=>$add) {
            if(!Schema::hasColumn('generation_jobs',$key))
                Schema::table('generation_jobs',static function(Blueprint $t)use($add){$add($t);});
        }
    }

    public function down():void
    {
        foreach(['request_background_music','request_video_mode'] as $key)
            if(Schema::hasColumn('generation_jobs',$key))
                Schema::table('generation_jobs',static function(Blueprint $t)use($key){$t->dropColumn($key);});
        foreach(['allow_background_music','video_mode'] as $key)
            if(Schema::hasColumn('projects',$key))
                Schema::table('projects',static function(Blueprint $t)use($key){$t->dropColumn($key);});
    }
};
