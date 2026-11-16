# AIDrama 风格库与创作技能库

AIDrama 内置可用的风格提示词模板与创作技能规则。所有内容均为**纯文本数据**，可按项目选择、组合，加入到剧本分镜、图片生成及视频提示词生成流程，不会执行外部 Python/Shell/第三方代码。

## 独立更新

在后台「检查更新」页面，程序版本下方展示 **视觉风格库** 和 **创作技能库**。点击顶部「检查更新」可同时检查程序和两种数据包；每个库也可以独立检查、独立更新。

远程数据只从官方 GitHub 仓库获取：
- [library/styles.json](../library/styles.json)：version、items（label/category/summary/prompt）
- [library/skills.json](../library/skills.json)：version、items（label/description/prompt/stages）

系统检查版本号，仅当远程版本更新时开放「更新」。更新前验证 JSON 结构、条目 ID、字段、长度及技能使用阶段；下载保存在服务器私有 `shared/storage/app/private/library`，采用原子替换，禁止写入 PHP、脚本与公开目录。

基础风格与技能始终随程序内置，独立更新包可以新增和覆盖同 ID 的提示词说明。不删除已存在的基础条目，不改变历史生成的图像，也不会覆盖管理员在「模型设置」里编辑的自定义模板。升级整个程序不会清空私有库版本。

## 在项目里使用

项目创建时选择「参考风格库」与横屏 16:9／竖屏 9:16，并可在「技能库」中组合角色连续性、动作时序、人物三视图、画面质量等规则。项目页面可随时调整这些设置；每个资产的画幅也可以单独覆盖。

生成时会根据阶段加入对应提示词：
- 剧本：风格细节与 `script` 阶段技能规则
- 图片：项目风格、默认/资产覆盖比例及 `image` 阶段技能
- 视频提示词：项目比例、风格与 `video` 阶段技能

这些都是描述性的参考提示词，不能保证模型输出一定符合特定比例；请结合实际图像模型的尺寸支持情况使用。不会复制或自动执行任何上游第三方 Skills 项目代码。历史参考项目：[zenstory-ai/drama-skills](https://github.com/zenstory-ai/drama-skills)。
