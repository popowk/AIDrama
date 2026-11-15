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
        'image'=>'角色 / 场景 / 道具图片',
        'kling'=>'可灵 Kling 视频',
        'seedance'=>'Seedance 视频',
    ];

    public const SOURCES=[
        'script'=>'参考 zenstory-ai/drama-skills 的剧本→资产→分镜流程（MIT）',
        'image'=>'参考公开图片生成 API 结构与创作规范',
        'kling'=>'依据 Kling 官方提示词指南整理：主体、动作、环境、镜头、光线',
        'seedance'=>'依据火山引擎 Seedance 官方提示词指南整理：主体、时间顺序、运动、运镜、参考素材',
    ];

    public const LINKS=[
        'script'=>'https://github.com/zenstory-ai/drama-skills',
        'image'=>'https://developers.openai.com/api/docs/guides/image-generation',
        'kling'=>'https://kling.ai/quickstart/text-to-video-prompt-guide',
        'seedance'=>'https://docs.volcengine.com/docs/ark/seedance-2-0-prompt-guide?lang=zh',
    ];

    private const DEFAULTS=[
        'script'=><<<'PROMPT'
你是专业影视分镜导演和资产统筹，参考开源 Drama Skills 的剧本、资产、分镜逐步拆解方法。
首先判断创作形式：talking＝纯口播，真人/动画角色主要面对镜头说话；mixed＝画面文案、情节/产品展示加同期口播或画外音。
纯口播：尽量保持同一角色、场景与机位连贯，不随意添加情节、场景和人物；每镜突出表情、肢体与台词顺序。
文案加口播：分别设计画面动作、必要的屏幕文字和口播；将画外音与镜头主体对应，禁止把全部文案都转成画面中的人物台词。
依时间顺序拆镜头，明确说话之前必须完成的动作。单镜时长严格遵守限制；长对白可按顺序继续拆分。
人物三视图、场景和道具要求统一视觉设定；严禁无中生有创造未出现角色。
识别客户必须提供的品牌 Logo、具体型号、真实产品/包装/商标，设定为 upload；只有普通角色、非品牌场景和非品牌道具可 generate。
仅输出调用方约定的 JSON，不输出 Markdown。
PROMPT,
        'image'=><<<'PROMPT'
依据项目画风和资产文字设定生成清晰可辨的单张参考图。人物用正面、侧面、背面三视图且形象一致，场景用完整环境建立镜头，普通道具用高辨识度的结构参考。
统一色彩、服装、光线和材质。不要自行添加品牌、Logo、产品型号、标语、文字或水印。不可用生成结果冒充客户真实产品照片。
PROMPT,
        'kling'=><<<'PROMPT'
请遵循可灵公开指南推荐的要素顺序：主体及特征 → 主体动作 → 场景与环境 → 镜头景别/运镜 → 光线氛围。
动作表达要具体，避免同一镜头出现冲突运镜。按镜头时长控制动作数量；有对白时标注发声角色和先后顺序。
如果涉及 Logo、品牌产品或真实产品型号，严格使用用户上传的参考素材，不凭空重新设计。
PROMPT,
        'seedance'=><<<'PROMPT'
请遵循 Seedance 公开视频创作指南的写法：明确主体、画面起始状态、按时间顺序的动作、摄影机调度、最终状态和声音/口播。
如有多镜头须标注镜头衔接，人物、服饰与场景前后统一。分别注明画内说话与画外音，以及需要叠加的字幕/文案。
若有客户上传的 Logo、产品图等，需作为真实视觉参考；不要由模型推测其图案或文字。
PROMPT,
    ];

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
        return self::mode($kind)==='custom'&&trim(self::custom($kind))!==''
            ?self::custom($kind):self::reference($kind);
    }

    public static function save(string $kind,string $mode,string $reference,string $custom):void
    {
        self::ensure($kind);
        if(!in_array($mode,['reference','custom'],true)||trim($reference)===''||
           ($mode==='custom'&&trim($custom)===''))
            throw ValidationException::withMessages(['prompt'=>'请填写默认参考提示词；自定义模式必须有提示词']);
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
