<?php
namespace App\Services;

class AssetOrigin
{
    public static function needsUpload(string $type,string $name,string $description,string $hint=''):bool
    {
        if($hint==='upload')return true;
        // The generated script may omit the field. Fail safe for identifiable
        // commercial/product artwork; never hallucinate client brand geometry.
        if(preg_match('/logo|商标|品牌标识|企业标识|产品图|产品外观|真实产品|包装盒|铭牌|产品型号|jovision|中维世纪/iu',$name))
            return true;
        if($type==='prop' && preg_match('/产品|型号|摄像头|摄像机|球机|枪机|NVR|DVR|监控设备|太阳能板|4G监控|AOV|K3C/iu',$name))
            return true;
        if($type==='prop' && preg_match('/品牌标识|真实型号|产品外观|客户提供|必须上传|logo|商标/iu',$description))
            return true;
        return false;
    }
}
