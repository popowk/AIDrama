<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Deliberately self-contained:
 * The web updater runs NEW migrations inside the currently active OLD Laravel
 * process. Never reference new App\ classes or services from here.
 *
 * MySQL 5.7 DDL is not transactional. An interrupted previous attempt may
 * already have added some columns, so check each column separately.
 */
return new class extends Migration {
    public function up(): void
    {
        $columns=[
            'generation_jobs'=>[
                'request_mode'=>static fn(Blueprint $t)=>$t->string('request_mode',16)->default('auto'),
            ],
            'projects'=>[
                'script_mode'=>static fn(Blueprint $t)=>$t->string('script_mode',16)->default('auto'),
                'detected_mode'=>static fn(Blueprint $t)=>$t->string('detected_mode',16)->nullable(),
            ],
            'shots'=>[
                'on_screen_text'=>static fn(Blueprint $t)=>$t->text('on_screen_text')->nullable(),
                'delivery'=>static fn(Blueprint $t)=>$t->string('delivery',16)->default('on_camera'),
            ],
            'assets'=>[
                'source_kind'=>static fn(Blueprint $t)=>$t->string('source_kind',16)->default('generate'),
                'image_status'=>static fn(Blueprint $t)=>$t->string('image_status',16)->default('pending'),
                'image_started_at'=>static fn(Blueprint $t)=>$t->timestamp('image_started_at')->nullable(),
                'image_error'=>static fn(Blueprint $t)=>$t->text('image_error')->nullable(),
            ],
        ];
        foreach($columns as $table=>$definitions) {
            if(!Schema::hasTable($table))
                throw new \RuntimeException('数据库缺少必需表 '.$table.'，请先检查旧版迁移状态');
            foreach($definitions as $column=>$create) {
                if(!Schema::hasColumn($table,$column))
                    Schema::table($table,static function(Blueprint $t) use ($create) {
                        $create($t);
                    });
            }
        }

        // Backfill recognizable client-owned logos and branded hardware.
        // This logic must not depend on an application service unavailable
        // in the old release executing this new migration.
        $needsUpload=static function(string $type,string $name,string $description):bool {
            if(preg_match('/logo|商标|品牌标识|企业标识|产品图|产品外观|真实产品|包装盒|铭牌|产品型号|jovision|中维世纪/iu',$name))
                return true;
            if($type==='prop' &&
               preg_match('/产品|型号|摄像头|摄像机|球机|枪机|NVR|DVR|监控设备|太阳能板|4G监控|AOV|K3C/iu',$name))
                return true;
            if($type==='prop' &&
               preg_match('/品牌标识|真实型号|产品外观|客户提供|必须上传|logo|商标/iu',$description))
                return true;
            return false;
        };
        DB::table('assets')->orderBy('id')->chunkById(100,
            static function($assets) use ($needsUpload):void {
                foreach($assets as $asset) {
                    if($needsUpload((string)$asset->type,(string)$asset->name,
                        (string)$asset->description)) {
                        DB::table('assets')->where('id',$asset->id)
                            ->where('source_kind','generate')
                            ->update(['source_kind'=>'upload']);
                    }
                }
            }
        );
    }

    public function down(): void
    {
        $columns=[
            'generation_jobs'=>['request_mode'],
            'assets'=>['source_kind','image_status','image_started_at','image_error'],
            'shots'=>['on_screen_text','delivery'],
            'projects'=>['script_mode','detected_mode'],
        ];
        foreach($columns as $table=>$names) {
            if(!Schema::hasTable($table))continue;
            foreach($names as $name)
                if(Schema::hasColumn($table,$name))
                    Schema::table($table,static function(Blueprint $t) use ($name):void {
                        $t->dropColumn($name);
                    });
        }
    }
};
