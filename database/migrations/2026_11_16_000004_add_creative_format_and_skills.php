<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Old release executes new migrations during upgrades. No App classes.
 * MySQL 5.7 DDL can partially commit: every column must be checked.
 */
return new class extends Migration {
    public function up():void
    {
        $columns=[
            'projects'=>[
                'frame_ratio'=>static fn(Blueprint $t)=>$t->string('frame_ratio',16)->default('landscape'),
                'style_preset'=>static fn(Blueprint $t)=>$t->string('style_preset',64)->default('custom'),
                'creative_skills'=>static fn(Blueprint $t)=>$t->text('creative_skills')->nullable(),
            ],
            'assets'=>[
                'frame_ratio'=>static fn(Blueprint $t)=>$t->string('frame_ratio',16)->default('inherit'),
            ],
            'generation_jobs'=>[
                'request_ratio'=>static fn(Blueprint $t)=>$t->string('request_ratio',16)->default('landscape'),
                'request_style_preset'=>static fn(Blueprint $t)=>$t->string('request_style_preset',64)->default('custom'),
                'request_skills'=>static fn(Blueprint $t)=>$t->text('request_skills')->nullable(),
            ],
        ];
        foreach($columns as $table=>$defs) {
            if(!Schema::hasTable($table))
                throw new RuntimeException('Missing required table '.$table);
            foreach($defs as $name=>$create)
                if(!Schema::hasColumn($table,$name))
                    Schema::table($table,static function(Blueprint $t)use($create){$create($t);});
        }
    }
    public function down():void
    {
        foreach([
            'generation_jobs'=>['request_skills','request_style_preset','request_ratio'],
            'assets'=>['frame_ratio'],
            'projects'=>['creative_skills','style_preset','frame_ratio'],
        ] as $table=>$columns)
            if(Schema::hasTable($table))
                foreach($columns as $name)
                    if(Schema::hasColumn($table,$name))
                        Schema::table($table,static function(Blueprint $t)use($name){$t->dropColumn($name);});
    }
};
