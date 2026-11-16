<?php
namespace App\Services;

/**
 * Built-in textual style & craft skills. Data, never executable code:
 * these entries can be selected per project and composed into AI prompts.
 * The input and identity constraints are still enforced elsewhere.
 */
class CreativeLibrary
{
    public const STYLES=[
        'custom'=>[
            'label'=>'自定义风格','category'=>'通用','summary'=>'使用项目手动填写的画风，不额外添加风格词',
            'prompt'=>'',
        ],
        'guofeng_3d'=>[
            'label'=>'3D 国风动画','category'=>'动画','summary'=>'东方建筑、柔和写实材质、电影级三维灯光',
            'prompt'=>'东方幻想题材的高品质三维动画美术：真实空间纵深，精致但不夸张的角色比例，汉式建筑与木石纹理，丝绸和布料层次明确；暖金主光与冷蓝轮廓光配合，雾气与环境反射克制。镜头采用电影构图与自然透视，角色的发型、服装、材质和色板跨镜稳定。避免照片质感、廉价塑料表面和复杂到难辨识的背景。',
        ],
        'chinese_ink'=>[
            'label'=>'国风水墨','category'=>'东方美学','summary'=>'留白、墨色层次、山水氤氲、宣纸质感',
            'prompt'=>'中国水墨画视觉语言，宣纸纤维细微可见，焦墨、浓墨、淡墨层次与水痕自然晕染；主体轮廓用简练书写线，远山与云雾虚实相生，近景重笔远景留白。控制色彩为墨黑、灰青与少量赭红，视觉宁静，避免全画面过分填满及高饱和科幻灯效。',
        ],
        'chinese_gongbi'=>[
            'label'=>'新中式工笔','category'=>'东方美学','summary'=>'细线勾勒、矿物色、精工纹样与典雅秩序',
            'prompt'=>'新中式工笔绘画质感，轮廓使用细密而一致的线描，衣纹和建筑纹样精确；石青、石绿、朱砂与米白互相制衡，淡淡的绢本肌理。画面有层次、明晰的前中后景与均衡构图，人物五官稳定，不使用随机文字题款。',
        ],
        'anime_cel'=>[
            'label'=>'日式赛璐璐动画','category'=>'动画','summary'=>'干净线稿、分层色块、清晰面部表情',
            'prompt'=>'高完成度二维赛璐璐动画，干净匀称的角色轮廓线与局部强调线，明暗使用清晰的两至三级色块，眼神和表情有情绪变化；环境采用手绘背景与空气透视。色调统一、角色发型服饰稳定，避免写实皮肤毛孔、三维塑料感和无关水印。',
        ],
        'anime_3d'=>[
            'label'=>'二次元三渲二','category'=>'动画','summary'=>'3D 空间结合二维描边和动漫化阴影',
            'prompt'=>'三渲二动画流程感：真实三维体块和准确透视，二维细描边与少量分层卡通阴影，材质柔和、轮廓清楚；动作关键姿态有足够剪影识别度，镜头光照连续。避免厚重真实皮肤反射和不一致的混合画风。',
        ],
        'pixar_like_3d'=>[
            'label'=>'高品质风格化 3D','category'=>'动画','summary'=>'圆润角色造型、丰富材质、亲和力与电影布光',
            'prompt'=>'精致风格化三维角色动画，头身比例适度夸张但仍可信，眼神富有表现力，圆润几何体与柔软材质结合，次表面散射自然，毛发与织物有细节；温暖三点式布光，构图清晰、背景不过分抢戏。保持原创造型，不模仿具体知名动画角色。',
        ],
        'stop_motion'=>[
            'label'=>'黏土定格动画','category'=>'动画','summary'=>'可见手作质感、微缩模型、柔和实景光',
            'prompt'=>'真实黏土和手工微缩模型的定格动画视觉，黏土指纹痕迹与细微不完美可见，布景由纸张、木材和软陶组成，灯光像微缩摄影棚，景深较浅；动作定格姿态有可读性。不要生成过分光滑的数字塑料表面。',
        ],
        'realistic_film'=>[
            'label'=>'电影写实','category'=>'影视','summary'=>'自然皮肤材质、真实摄影机、低调电影调色',
            'prompt'=>'电影摄影质感，可信自然光和物理正确材质，肤色正常不过度磨皮；镜头按叙事情绪选择景别，适度浅景深与局部对焦，暗部有细节且无明显噪点，色彩保持有控制的电影调色，人物与场景比例真实。',
        ],
        'documentary'=>[
            'label'=>'纪录片纪实','category'=>'影视','summary'=>'现场光、生活化构图、克制后期',
            'prompt'=>'纪录片真实现场摄影，手持相机的自然高度、可信现场光、自然的人物姿态与环境细节；色彩克制不过分电影化，镜头平实、客观、有地理和时间线索。避免舞台化灯效、过度摆拍和虚构品牌。',
        ],
        'cyberpunk'=>[
            'label'=>'赛博朋克科技','category'=>'科幻','summary'=>'深色城市、霓虹层次、界面投影与雨夜反射',
            'prompt'=>'未来都市科技美术，深蓝青绿与少量洋红霓虹形成高对比，湿润石材金属反射自然，信息面板采用清晰的结构化层次，前景人物剪影与远景灯海互相衬托；画面有体积雾而不过曝，不出现无意义乱码。',
        ],
        'sci_fi_clean'=>[
            'label'=>'极简硬核科幻','category'=>'科幻','summary'=>'精密工业设计、清洁材质、清晰结构',
            'prompt'=>'近未来硬核科幻工业审美，浅灰金属、陶瓷、玻璃与蓝色数据指示光构成统一体系；设备结构和连接关系符合功能，画面留白、对称或网格布局，信息图形克制。重点突出真实工程逻辑，避免不明用途的悬浮部件。',
        ],
        'watercolor'=>[
            'label'=>'手绘水彩绘本','category'=>'插画','summary'=>'柔和晕染、纸张纹理、轻盈叙事',
            'prompt'=>'细腻手绘水彩绘本，湿画法边缘渐变、纸张肌理、少量铅笔线稿辅助，色彩清新有温度；前中后景以明度控制层次，保留适度留白，不要出现强烈三维塑料感和数字噪声。',
        ],
        'noir_comic'=>[
            'label'=>'黑白漫画分镜','category'=>'插画','summary'=>'墨线、网点、强烈黑白关系和戏剧角度',
            'prompt'=>'黑白漫画分镜美术，精确有节奏的黑色墨线和网点灰阶，高反差明暗块，强烈但合理的透视与景别变化；通过人物肢体、视线与空间关系表达剧情，保持面部和服饰连续。无额外对白框和不必要的拟声文字。',
        ],
        'lowpoly'=>[
            'label'=>'低多边形美术','category'=>'插画','summary'=>'明确几何体、克制材质、清爽色彩',
            'prompt'=>'低多边形三维视觉，物件以整洁的平面几何体和清晰的面片转折塑造，材质简洁、结构高辨识度；环境光柔和，鲜明的互补色布局，避免过多细碎真实纹理或高频噪点。',
        ],
    ];

    public const SKILLS=[
        'continuity'=>[
            'label'=>'角色与场景连续性锁','stages'=>['script','image','video'],
            'description'=>'相邻镜头沿用明确的服装、材质、发型与物品位置',
            'prompt'=>'建立角色与场景连续性锁：角色外观、发型、服装、配饰、场景布局、关键道具位置和光线方向应保持跨镜一致；如有变化，必须由剧情动作解释。',
        ],
        'story_order'=>[
            'label'=>'剧本动作时序拆镜','stages'=>['script','video'],
            'description'=>'动作、说话、转场分开，不遗漏先后顺序',
            'prompt'=>'把先发生的动作与之后的台词分开说明：每镜交代起始状态、动作顺序、说话角色和结束状态；禁止把本应先完成的动作放到台词之后。',
        ],
        'character_sheet'=>[
            'label'=>'角色三视图规范','stages'=>['image'],
            'description'=>'正面、侧面、背面统一角色的细节',
            'prompt'=>'人物资产应同画布展示正面、完整侧面与背面三视图；人物必须从头至脚完整入框，站姿自然，三面比例、服装与头饰完全一致，并留足安全边距。',
        ],
        'scene_establishing'=>[
            'label'=>'场景建立镜头','stages'=>['image','video'],
            'description'=>'广角展现动线、出入口和景深层次',
            'prompt'=>'场景资产优先清楚交代空间全貌、出入口、主体建筑、道路与视觉动线；光线方向统一，前中后景清楚，避免人物无关闯入。',
        ],
        'prop_design'=>[
            'label'=>'道具结构展示','stages'=>['image'],
            'description'=>'突出普通道具轮廓、材质与使用结构',
            'prompt'=>'道具资产突出单件主物体，镜头 3/4 视角或正侧剖面，比例结构可信，关键接口、材质与功能关系清楚；不自行创作品牌 Logo 或虚构参数。',
        ],
        'cinematic_camera'=>[
            'label'=>'电影景别与运镜','stages'=>['script','image','video'],
            'description'=>'在每镜写明景别、机位、摄影机方向与光线',
            'prompt'=>'优先明确景别、机位高度、镜头视角、透视和光线动机；每个镜头只使用可执行的运镜，不用互相冲突的摇镜、推镜和跟拍描述。',
        ],
        'screen_text'=>[
            'label'=>'字幕与画面信息安全区','stages'=>['script','image','video'],
            'description'=>'重要主体避开文字区域，屏幕文字由后期叠加',
            'prompt'=>'重要主体留出画面安全边距及需要的字幕位置；屏幕信息只写用户明确指定的文字。能由后期添加的精确信息不要交给图像模型胡乱描绘。',
        ],
        'quality_check'=>[
            'label'=>'画面质量审查','stages'=>['image','video'],
            'description'=>'检查比例、构图、主体完整、素材一致性',
            'prompt'=>'最终输出检查：尺寸和指定方向是否正确、主要主体是否完整、人物四肢及三视图没有裁切、无额外角色与错误标识；和前后镜头的造型、光源和材质保持一致。',
        ],
    ];

    public static function styles():array{return self::STYLES;}
    public static function skills():array{return self::SKILLS;}

    public static function stylePrompt(?string $key):string
    {
        return (string)(self::STYLES[$key??'custom']['prompt']??'');
    }

    public static function skillIds(mixed $value):array
    {
        if(is_string($value))$value=json_decode($value,true);
        if(!is_array($value))return [];
        return array_values(array_unique(array_filter($value,
            static fn($id)=>is_string($id)&&array_key_exists($id,self::SKILLS))));
    }

    public static function skillPrompt(mixed $value,string $stage):string
    {
        $text=[];
        foreach(self::skillIds($value) as $id) {
            $entry=self::SKILLS[$id];
            if(in_array($stage,$entry['stages'],true))$text[]=$entry['prompt'];
        }
        return implode("\n",$text);
    }

    public static function projectContext(object $project,string $stage):string
    {
        $parts=[];
        $style=self::stylePrompt($project->style_preset??'custom');
        if($style!=='')$parts[]="风格细化：".$style;
        $skills=self::skillPrompt($project->creative_skills??null,$stage);
        if($skills!=='')$parts[]="项目选用技能：\n".$skills;
        return implode("\n",$parts);
    }
}
