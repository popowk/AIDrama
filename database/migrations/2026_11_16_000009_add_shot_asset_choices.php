<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        if(!Schema::hasColumn('shots','asset_selection_mode'))
            Schema::table('shots',static function(Blueprint $t) {
                $t->string('asset_selection_mode',12)->default('auto');
            });
        if(!Schema::hasTable('shot_asset_links'))
            Schema::create('shot_asset_links',static function(Blueprint $t) {
                $t->unsignedBigInteger('shot_id');
                $t->unsignedBigInteger('asset_id');
                $t->timestamps();
                $t->primary(['shot_id','asset_id']);
                $t->foreign('shot_id')->references('id')->on('shots')->cascadeOnDelete();
                $t->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            });
    }
    public function down():void {
        Schema::dropIfExists('shot_asset_links');
        if(Schema::hasColumn('shots','asset_selection_mode'))
            Schema::table('shots',static function(Blueprint $t){$t->dropColumn('asset_selection_mode');});
    }
};
