<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Editable guidance templates. "reference" is an AIDrama summary of public
 * guidance, never an assertion that a provider published these exact words.
 */
class PromptPresets
{
    public const LABELS=[
        'script'=>'分镜拆解与口播类型识别',
        'image'=>'通用图片（旧版兼容，不再生效）',
        'image_character'=>'人物生图提示词',
        'image_scene'=>'场景生图提示词',
        'image_prop'=>'道具生图提示词',
        'kling'=>'可灵 Kling 视频',
        'seedance'=>'Seedance 视频',
    ];

    public const SOURCES=[
        'script'=>'参考 zenstory-ai/drama-skills 的剧本→资产→分镜流程（MIT）',
        'image'=>'旧版通用图片生图模板',
        'image_character'=>'人物形象与正面、侧面、背面三视图参考',
        'image_scene'=>'场景环境建立镜头与空间布局参考',
        'image_prop'=>'普通道具结构与材质表现参考',
        'kling'=>'依据 Kling 官方提示词指南整理：主体、动作、环境、镜头、光线',
        'seedance'=>'依据火山引擎 Seedance 官方提示词指南整理：主体、时间顺序、运动、运镜、参考素材',
    ];

    public const LINKS=[
        'script'=>'https://github.com/zenstory-ai/drama-skills',
        'image'=>'https://developers.openai.com/api/docs/guides/image-generation',
        'image_character'=>'https://developers.openai.com/api/docs/guides/image-generation',
        'image_scene'=>'https://developers.openai.com/api/docs/guides/image-generation',
        'image_prop'=>'https://developers.openai.com/api/docs/guides/image-generation',
        'kling'=>'https://kling.ai/quickstart/text-to-video-prompt-guide',
        'seedance'=>'https://docs.volcengine.com/docs/ark/seedance-2-0-prompt-guide?lang=zh',
    ];

    private const DEFAULTS=[
        'script'=><<<'PROMPT'
你是专业影视分镜导演和资产统筹，参考开源 Drama Skills 的剧本、资产、分镜逐步拆解方法。
首先判断创作形式：talking＝主要面对镜头口播；mixed＝情节/产品展示、人物之间对白、同期口播或画外旁白。
每个分镜都必须识别原始剧本中的说话内容，dialogue 按“人物名：原话”逐行保留原句、说话人和顺序，不可省略或合并成无声画面。
声音呈现 delivery：conversation 为人物之间看向彼此交流，on_camera 为对镜头讲话，voiceover 为画外旁白，none 仅用于确实没有说话的镜头。
镜头 visual 要写明说话者、听者、视线、同步动作和口型；若人物边做动作边说话，安排在同一镜头，不因表情变化额外拆镜；只有必须先完成的动作才放在 action_before。
对白和声音字段虽不在分镜编辑界面单独显示，模型输出 JSON 时仍必须完整生成。短视频不生成字幕和叠加文案，默认不生成背景音乐。
依时间顺序拆镜头。单镜时长严格遵守限制；长对白可按说话人顺序继续拆分。
人物三视图、场景和道具要求统一视觉设定；严禁无中生有创造未出现角色。
识别客户必须提供的品牌 Logo、具体型号、真实产品/包装/商标，设定为 upload；只有普通角色、非品牌场景和非品牌道具可 generate。
仅输出调用方约定的 JSON，不输出 Markdown。
PROMPT,
        'image'=><<<'PROMPT'
依据项目画风和资产文字设定生成清晰可辨的单张参考图。人物用正面、侧面、背面三视图且形象一致，场景用完整环境建立镜头，普通道具用高辨识度的结构参考。
统一色彩、服装、光线和材质。不要自行添加品牌、Logo、产品型号、标语、文字或水印。不可用生成结果冒充客户真实产品照片。
PROMPT,
        'image_character'=><<<'PROMPT'
根据人物资产描述生成同一个角色的完整三视图：正面、侧面、背面，三个人物形象一致，脸型、发型、身形、服装、道具、鞋靴保持统一；三视图同时完整出现在画面安全区内。
统一项目画风、光照、摄影材质。清晰呈现自然姿势、真实衣料、面部纹理和全身轮廓；背景简洁，不添加人物之外的新角色或未说明的标识、型号和文字。
PROMPT,
        'image_scene'=><<<'PROMPT'
根据场景资产描述生成完整环境建立镜头。重点表现前景、中景、远景的空间关系、道路建筑位置、环境地貌、整体氛围及日光或场景光线；重要场景内容完整保留在画面安全区内。
统一项目画风、材质、时间和透视，避免夸张畸变和不必要的背景虚化。场景参考图不采用角色三视图布局，不添加未提供的品牌、文字或水印。
PROMPT,
        'image_prop'=><<<'PROMPT'
根据普通道具资产描述生成清晰的物体结构参考图。主体完整、边缘结构可辨，强调大小比例、材质、接口、组合关系和关键细节；必要时采用合理的多角度观察方式，不套用人物三视图。
统一项目画风及真实物理材质，画面背景简洁。不自行添加商标、Logo、具体型号或包装文案；真实品牌产品应使用客户提供的素材。
PROMPT,
        'kling'=><<<'PROMPT'
请遵循可灵公开指南推荐的要素顺序：主体及特征 → 主体动作 → 场景与环境 → 镜头景别/运镜 → 光线氛围。
动作表达要具体，避免同一镜头出现冲突运镜。按镜头时长控制动作数量；有对白时标注发声角色和先后顺序。
如果涉及 Logo、品牌产品或真实产品型号，严格使用用户上传的参考素材，不凭空重新设计。
PROMPT,
        'seedance'=><<<'PROMPT'
请遵循 Seedance 公开视频创作指南的写法：明确主体、画面起始状态、按时间顺序的动作、摄影机调度、最终状态和声音/口播。
如有多镜头须标注镜头衔接，人物、服饰与场景前后统一。分别注明人物之间对白、对镜头口播与画外音，保留原话及发言顺序；默认不生成字幕或背景音乐。
若有客户上传的 Logo、产品图等，需作为真实视觉参考；不要由模型推测其图案或文字。
PROMPT,
    ];

    public const VISIBLE_KINDS=['script','image_character','image_scene','image_prop','kling','seedance'];

    public static function reference(string $kind):string
    {
        self::ensure($kind);
        return DB::table('settings')->where('key','prompt.'.$kind.'.reference')->value('value')
            ?:self::DEFAULTS[$kind];
    }

    public static function defaultText(string $kind):string
    {
        self::ensure($kind);
        return self::DEFAULTS[$kind];
    }

    public static function custom(string $kind):string
    {
        self::ensure($kind);
        return (string)(DB::table('settings')->where('key','prompt.'.$kind.'.custom')->value('value')??'');
    }

    public static function mode(string $kind):string
    {
        self::ensure($kind);
        return DB::table('settings')->where('key','prompt.'.$kind.'.mode')->value('value')==='custom'
            ?'custom':'reference';
    }

    public static function active(string $kind):string
    {
        // Selecting an empty custom prompt means no extra prompt instructions.
        // Never silently inject the reference text in this mode.
        return self::mode($kind)==='custom' ? self::custom($kind) : self::reference($kind);
    }

    public static function save(string $kind,string $mode,string $reference,string $custom):void
    {
        self::ensure($kind);
        if(!in_array($mode,['reference','custom'],true)||trim($reference)==='')
            throw ValidationException::withMessages(['prompt'=>'请填写默认参考提示词']);
        DB::transaction(function()use($kind,$mode,$reference,$custom) {
            foreach(['mode'=>$mode,'reference'=>$reference,'custom'=>$custom] as $field=>$value)
                DB::table('settings')->updateOrInsert(
                    ['key'=>'prompt.'.$kind.'.'.$field],
                    ['value'=>$value,'updated_at'=>now()]
                );
        });
    }

    public static function reset(string $kind):void
    {
        self::ensure($kind);
        DB::table('settings')->where('key','prompt.'.$kind.'.reference')->delete();
        DB::table('settings')->updateOrInsert(
            ['key'=>'prompt.'.$kind.'.mode'],['value'=>'reference','updated_at'=>now()]
        );
    }

    public static function ensure(string $kind):void
    {
        if(!array_key_exists($kind,self::LABELS))abort(404);
    }
}
