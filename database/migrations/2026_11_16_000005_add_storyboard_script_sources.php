<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Idempotent per-column: MySQL 5.7 may partially commit ALTER TABLE.
        foreach([
            'input_kind'=>static fn(Blueprint $t)=>$t->string('input_kind',20)->default('script'),
            'storyboard_source'=>static fn(Blueprint $t)=>$t->longText('storyboard_source')->nullable(),
            'script_segments'=>static fn(Blueprint $t)=>$t->longText('script_segments')->nullable(),
            'storyboard_dirty'=>static fn(Blueprint $t)=>$t->boolean('storyboard_dirty')->default(false),
        ] as $name=>$create) {
            if(!Schema::hasColumn('projects',$name))
                Schema::table('projects',static function(Blueprint $t)use($create){$create($t);});
        }
        foreach([
            'kind'=>static fn(Blueprint $t)=>$t->string('kind',20)->default('shots'),
            'request_storyboard'=>static fn(Blueprint $t)=>$t->longText('request_storyboard')->nullable(),
        ] as $name=>$create) {
            if(!Schema::hasColumn('generation_jobs',$name))
                Schema::table('generation_jobs',static function(Blueprint $t)use($create){$create($t);});
        }
    }
    public function down(): void
    {
        foreach(['request_storyboard','kind'] as $name)
            if(Schema::hasColumn('generation_jobs',$name))
                Schema::table('generation_jobs',static function(Blueprint $t)use($name){$t->dropColumn($name);});
        foreach(['storyboard_dirty','script_segments','storyboard_source','input_kind'] as $name)
            if(Schema::hasColumn('projects',$name))
                Schema::table('projects',static function(Blueprint $t)use($name){$t->dropColumn($name);});
    }
};
