<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // MySQL 5.7 DDL is not transactional. Check each column separately
        // so a partially completed upgrade can be retried safely.
        foreach([
            'is_archived'=>static fn(Blueprint $t)=>$t->boolean('is_archived')->default(false),
            'prompt_override'=>static fn(Blueprint $t)=>$t->boolean('prompt_override')->default(false),
        ] as $column=>$add) {
            if(!Schema::hasColumn('assets',$column))
                Schema::table('assets',static function(Blueprint $t)use($add){$add($t);});
        }
    }
    public function down(): void
    {
        foreach(['prompt_override','is_archived'] as $column)
            if(Schema::hasColumn('assets',$column))
                Schema::table('assets',static function(Blueprint $t)use($column){$t->dropColumn($column);});
    }
};
