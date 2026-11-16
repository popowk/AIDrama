# AIDrama · AI 剧本与分镜工作台

**安装位置**：程序自动从自身 PHP 文件所在位置解析根目录，不依赖域名、站点文件夹名称或固定的 `/www/wwwroot/...` 路径。

**运行环境**：宝塔 OpenResty + PHP 8.4 + MySQL 5.7.44；Blade + 原生 JavaScript，无需服务器安装 Node.js、Composer、Redis 或 Python。

## 全新安装：只下载一个 ZIP

前往 [最新 GitHub Release](https://github.com/popowk/AIDrama/releases/latest)，**只下载 `AIDrama.zip`**。程序、PHP 依赖与图形化安装器已经整合在一起，不再有单独的 Installer.zip。

1. 在宝塔新建站点，PHP 版本设为 8.4，**网站目录使用你在宝塔中为站点创建的实际路径**，例如 `/www/wwwroot/你的站点目录`，运行目录设为 `/public`，即网站实际根目录为 `你的站点目录/public`。启用 HTTPS。**须使用全新空目录**，不要在现有程序上解压。程序不会要求目录名叫 `AIDrama`。
2. 将 `AIDrama.zip` 上传到**当前宝塔站点实际目录**并在该目录解压（不要再套一层 `AIDrama/`），随后可删除已上传的 ZIP 文件。解压后应存在 `当前站点目录/public/index.php` 和 `当前站点目录/releases/vX.Y.Z/`。
3. 在宝塔创建一个**全新的空 MySQL 数据库**及专用账号，记下其账号和密码。即使旧文件已经删除，**旧数据库的表仍可能存在**；不要复用旧库。管理员密码不限制长度和复杂度，只要求非空且两次输入一致。
4. 通过 HTTPS 访问该网站。首页会显示 **AIDrama Setup** 安装向导；在宝塔文件管理器中打开 `当前站点目录/shared/setup-token.txt`，复制一次性口令到安装页面验证身份。
5. 安装页检查 PHP 8.4、所需扩展、目录和站点根路径，填写数据库信息及管理员密码，点击一键安装。安装会使用 ZIP 中的完整代码，不会再从 GitHub 下载第二份安装包。

**注意**：首次安装需启用 PHP 扩展 `fileinfo`、`curl`、`zip`、`mbstring`、`openssl`、`pdo_mysql` 等。PHP-FPM 进程应能写入 AIDrama 根目录及 `shared/` 和 `releases/`；宝塔 PHP 防跨站配置要允许站点 PHP 访问整个 AIDrama 目录。数据库建议限制为仅本机可访问。只对全新、空的 AIDrama 目录解压安装包，**不要把安装包覆盖已有站点**。

## 为什么还有 releases 目录？

对外的网站运行根目录始终是：

```text
<实际网站目录>/public
```

程序目录（从浏览器不能直接访问）：

```text
AIDrama/
├── public/                 # 唯一 Web 公开目录，包含入口和 CSS
├── bootstrap/installer.php # 安装逻辑，位于 Web 根目录之外
├── releases/
│   └── vX.Y.Z/            # 完整 Laravel 代码及 vendor
├── deploy/update.php       # 固定路径的 GitHub 更新器

└── shared/
    ├── .env               # 私有 APP_KEY、数据库配置
    ├── active-version     # 当前版本号（文本，不使用软链接）
    ├── storage/            # 私有登录哈希、素材、日志
    └── install.lock        # 安装完成标记
```

内部更新及回滚通过 `shared/active-version` 文本标记完成，**不调用 `symlink()`**，也不需要你在宝塔设置 `current` 目录。正常时读取指针指定的完整版本（先迁移数据库再切换）；如果指针失效或目录被删除，入口会选择 `releases/vX.Y.Z` 下**版本号最高且文件完整的本地版本**并尝试修复指针。若没有任何完整版本，会显示明确的修复提示。

不会创建 `public/storage` 软链接，也不会把用户素材、数据库备份、API Key、日志或 `.env` 放在公开目录。发布包也不含任何用户凭据。

### 宝塔伪静态

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

如宝塔已配置 Laravel 相同规则，不要重复添加。在网站根目录和 HTTPS 设置正确前，不要开放安装表单。

## 全新安装保护机制

首次安装只允许空网站配置与空数据库：网页向导会在私有目录生成一次性口令，并自动生成全新的应用加密密钥、创建数据库表、保存管理员密码哈希和锁定安装入口。如果检测到旧 `.env`、管理员数据、安装锁或旧数据库表，会直接停止，而**不会擅自删除任何文件或数据库**。

安装器、版本号标记、系统日志、数据库连接信息及生成素材都保存在 `public/` 之外；PHP 禁用 `symlink()` 仍然能够安装。不要关闭宝塔的相关安全限制来解决兼容性问题。

### 删除旧版本后显示 AIDrama is temporarily unavailable

这是旧版网站入口仍指向已经删除的版本导致的。无需清空数据库或删除 `shared/`。将最新版完整 `AIDrama.zip` 解压到实际网站目录，覆盖根目录的 `public/index.php`，同时保留原 `shared/`、MySQL 数据库与素材。新版入口在原版本目录已不存在时，会从 `releases/` 选择最新的完整版本，尝试修复指针。

如果旧版本仍存在且可用，仅上传一个更高版本目录**不会自动跳过数据库迁移直接启用新代码**；正常升级需使用后台「一键更新」。也不建议手动删除运行中的版本；恢复机制的前提是仍有至少一个完整版本、数据库结构与该版本兼容。
## 模型设置：文本 / 图片分离

后台「模型设置」提供**左右并排**的独立表单：**文本模型**（API Base URL、模型名、API Key）用于分镜和角色场景文字分析；**图片模型**另有一组独立的 API 地址、模型名与密钥，可以调用兼容的 **`POST /images/generations`**，生成 PNG/JPG/WebP 真实图片并保存到私有素材目录。图片服务商需支持 `data[0].b64_json` 返回值（仅返回 URL 的接口不会自动抓取未知来源图片），如模型不兼容会明确提示。
API 密钥分别加密存储在 MySQL，页面不回显明文。旧文本模型配置兼容。

### 提示词模板与两类口播识别

「模型设置」下方提供 4 类独立提示词卡片：**剧本分析、图片、可灵 Kling、Seedance**。每类都能修改「公开参考版」和「我的自定义版」，选择当前生效版本，或恢复内置参考内容（恢复参考版时保留自定义文本）。参考内容是 AIDrama 按公开指南**原创整理**的工作规则，并不声称是官方原文。

- 分镜分析默认参考 [zenstory-ai/drama-skills (MIT)](https://github.com/zenstory-ai/drama-skills) 的剧本→资产→分镜流程。
- 可灵提示词参考 [Kling 官方文本生视频指南](https://kling.ai/quickstart/text-to-video-prompt-guide) 中主体、动作、场景、运镜、光线的组织方式。
- Seedance 提示词参考 [火山引擎官方 Seedance 2.0 提示词指南](https://docs.volcengine.com/docs/ark/seedance-2-0-prompt-guide?lang=zh)，包含动作先后、镜头调度和参考素材。
- 图片提示词参考 [OpenAI 官方图片生成接口指南](https://developers.openai.com/api/docs/guides/image-generation)，同时加上人物三视图、场景与道具一致性要求。

项目创建/编辑时可选择**自动识别**、**纯口播**或**文案＋口播**。自动识别由文本模型返回 `script_mode=talking|mixed`，分镜会区分 `dialogue`、`on_screen_text`（画面文案）和 `delivery`（人物说话、旁白或无口播），并带到视频提示词中。纯口播不会随意添加多余角色/故事；混合模式设计画面、字幕和旁白的配合。

### 真实产品上传与资产图片生成

分镜模型提取的素材带 `source_kind`：

- **客户必须上传**：Logo、品牌标识、真实产品/包装图、摄像头/球机等有型号的设备。上传 PNG/JPG/WebP、单张不超过 10MB，系统不会允许 AI 重新绘制它们来冒充真实产品。
- **可以 AI 生图**：普通角色、非品牌场景、普通道具。项目资产卡片有「AI 生成图片」或「一键生成普通资产图」按钮，使用你在设置中配置的图片模型。批量按钮顺序执行并提示可能产生 API 费用。

上传和生成的图片仅保存在 `shared/storage/app/private/projects/<project_id>/assets/`，须经过管理员身份检查才能访问预览，不在网站的 `public/` 目录建立链接。原客户素材会在项目重新生成分镜后按名称保留；旧项目中的明显 Logo / 产品资产会在升级迁移时补标为「必须上传」。

**当前可灵 / Seedance 功能是可复制的官方指南参考或自定义视频提示词，不代表直接调用视频生成 API。** 产品上传后提示词将标注必须使用参考图，但还需创作者在视频生成平台中实际附带上传图片。

**升级注意**：本版本新增了 MySQL 数据表字段，需要先备份数据库，再经后台「一键更新」执行迁移并切换版本。不要只将 `active-version` 直接改为新版本而跳过迁移。
## 模型返回较慢时：可恢复的数据库任务

点击 AI 生成后任务先写入 MySQL 的 generation_jobs 表，页面显示排队、运行、成功、失败，自动轮询进度。对模型网络超时、429/5xx 临时故障进行有限重试。原有分镜只有在新结果全部成功写入后才被替换，失败不会清空旧数据；长时间中断会提示重试。
无需 Redis、Supervisor 或常驻队列服务。浏览器发起单独 HTTP 请求等待模型，另一个请求可检查状态。**这不是完全脱离浏览器的后台队列**：PHP-FPM request_terminate_timeout、Nginx fastcgi_read_timeout 如短于模型请求时长，任务仍可能被中止。建议根据模型响应时间将相关超时合理设置为约 300～360 秒，前提是服务器容量允许；离开页面之前让任务开始运行。

## 后台检查更新和一键更新

管理员登录后点击顶部的「检查更新」，进入 `/updates`：

1. 点击「检查更新」显示当前版本及 GitHub 最新正式 Release。
2. 点击「一键更新」确认，PHP 下载 AIDrama.zip、验证 SHA256 并解压到新的私有版本目录。
3. 执行新版本尚未执行的数据库迁移，最后原子写入私有 active-version 标记切换版本；不依赖 symlink 或 shell exec。
4. 更新进度可显示检查、下载、校验、迁移和切换状态。成功切换后保留当前及上一版本用于回滚，并尝试清理更早、完整的旧版本目录。API 密钥、项目数据、日志及素材始终保存在 shared 私有目录，不从 release ZIP 覆盖。

**从旧版首次接入更新后台**：如果当前版本还没有更新入口，需要最后一次通过原有宝塔更新方式升级。若旧更新器提示迁移差异，确认数据库备份后可使用 `apply --allow-migrations` 切换代码；新版后台如检测到尚未执行的迁移，会出现「补齐数据库结构」按钮。以后日常更新可以直接在后台完成。

数据库迁移可能更改既有表结构，建议更新前在宝塔备份 MySQL。代码回滚不会自动回滚数据库，部分 MySQL DDL 不能完整回滚。首次使用请先在非生产环境验证。
## 更新方式

在 GitHub `main` 提交代码后，GitHub Actions 会生成新的 `AIDrama.zip` 完整 Release。**首次安装只需一次下载**；后续可在宝塔计划任务中，每小时以网站用户执行：

```bash
su -s /bin/sh www -c '/www/server/php/84/bin/php /实际网站目录/deploy/update.php apply'
```

更新器从 GitHub 最新 Release 下载完整包，只提取内部的新版本程序，不会覆盖 `public/index.php`、`shared/.env`、`shared/storage`、数据库或项目素材。更新使用 SHA256 校验、版本隔离及私有版本号标记，不需要软链接或 shell exec；升级涉及数据库迁移时自动停止，需要人工备份和审核。回滚仅恢复代码，不会自动回退数据库。

## 页面空白或 HTTP 500：如何诊断

首先在宝塔备份网站目录及数据库；**不要删 shared/、MySQL 或正在使用的 release**。新版本的 `public/index.php` 在捕获 PHP 启动异常时会返回带错误编号的 HTTP 500 页面，并将详情写入私有 `shared/storage/logs/bootstrap-error.log`；不是所有 Nginx、PHP-FPM 或 Laravel 捕获的错误都能由此处理。

在宝塔终端使用与你网站相同的 PHP 8.4 路径，执行以下只读检查。`<实际站点目录>` 替换为真实目录，脚本不会输出 API Key 或数据库密码。

```bash
/www/server/php/84/bin/php <实际站点目录>/deploy/diagnose.php
```

接着检查：

```bash
tail -n 60 <实际站点目录>/shared/storage/logs/bootstrap-error.log
tail -n 60 <实际站点目录>/shared/storage/logs/laravel.log
tail -n 60 /www/wwwlogs/<域名>.error.log
```

诊断会输出版本指针是否有效、release 文件完整性、共享缓存目录写入权限、Laravel 启动与 MySQL 连接是否成功、迁移缺失数量等。**CLI 与 PHP-FPM 可能加载不同的 php.ini 和 open_basedir**；命令行通过不代表网页一定正常。频繁覆盖版本后，可在宝塔中重启 PHP 8.4 服务以清理旧的 OPcache / PHP-FPM 进程，不要仅靠清空浏览器缓存。

如果诊断显示迁移未执行，先备份数据库，再从后台补齐迁移（或使用当前正式版的 `php artisan migrate --force` 并确认配置从 shared 加载），不要直接手动删除表或修改 migration 记录。若网站入口已经无法使用，先将最新 ZIP 解压到原站点目录覆盖 `public/index.php` 和完整版本代码，保留 shared/，然后重启 PHP-FPM 并查看上述日志。
### chatgpt2api 3.x 异步生图（推荐）

在「模型设置 → 图片模型」配置最新版 **yukkcat/chatgpt2api 3.x** 的 HTTPS API Base URL（以 \`/v1\` 结尾）、API Key 和实际支持的图片模型名称。图片生成模式选择「chatgpt2api 3.x · 异步任务」（默认），无需在 AIDrama 上等待一次长达数分钟的 \`/v1/images/generations\` 同步响应。

- AIDrama 通过 \`POST /api/image-tasks/generations\` 提交随机且持久保存的 \`client_task_id\`，即使提交确认被反向代理截断，也只用**同一任务 ID** 查询或幂等重试，避免重复生图。
- 已保存的任务通过 \`GET /api/image-tasks?ids=...\` 查询。页面刷新后继续；关闭页面后，chatgpt2api 可在服务端继续生成，重新打开项目页会恢复查询（需在上游任务保留期限内）。
- 上游任务成功后，从其 \`results[0].b64_json\` 或可信 **同 HTTPS 域名 /images/、/files/** URL 取得图片，校验尺寸与类型，并保存到 **AIDrama 私有 \`shared/storage\`**，不在项目页面直接暴露上游 URL。
- 可恢复的任务 \`actions.resume_poll=true\` 会自动尝试一次 \`POST /api/image-tasks/{task_id}/resume-poll\`；**不再次发起付费生成**。
- 如果上游始终找不到已经提交的任务，AIDrama 会保留原任务 ID 和错误提示，不能据此认定模型未生成。先通过 chatgpt2api 的任务页面确认，不要反复点击重新生成。
- 兼容其他仅有 \`/v1/images/generations\` 的图片服务时，手动切换为「通用 OpenAI 兼容 · 同步生成」。该兼容模式不会拥有 chatgpt2api 的异步结果找回能力。

**注意：** 为防止服务器端请求伪造，AIDrama 仅从已配置图片 API 的 **公网 HTTPS 主机**下载图片。上游返回同域名 HTTP 链接、反代泄露的 \`localhost:3000\` / 私有 IP URL 或以 \`/images/\` 开头的相对链接时，会先校验路径后转换到配置的 HTTPS 主机。仅允许 \`/images/\` 或 \`/files/\` 图片路径，不跟随跨域跳转；对于陌生第三方公网域名或其他路径仍拒绝下载。若 chatgpt2api 的 \`image_storage.public_base_url\` 指向不同的公网域名，应调整该设置而不是关闭安全校验。Laravel、MySQL 和私有素材目录均不因切换模式而删除或清空。

### HTTP 500：Target class [view] does not exist

在 v0.1.100 等旧版本的 GitHub Release 中，打包时排除了 `bootstrap/cache/` 目录；新版本目录又可能由宝塔上传程序设为 `mysql:mysql`。Laravel 在应用启动时无法写入 `packages.php`、`services.php`，可能最终表现为 `Target class [view] does not exist`。

**立即修复旧版的安全命令：**

```bash
SITE="/www/wwwroot/drama.tmd.pet"
VER="$(cat "$SITE/shared/active-version")"
CACHE="$SITE/releases/$VER/bootstrap/cache"
mkdir -p "$CACHE"
chown -R www:www "$CACHE"
chmod 750 "$CACHE"
rm -f "$CACHE/config.php" "$CACHE/services.php" "$CACHE/packages.php" "$CACHE/routes-v7.php"
```

随后在宝塔重启 PHP 8.4 并访问 `/login`。这只清理生成缓存，不修改用户数据、`shared/.env`、安装锁和数据库。若其他运行用户与 `www` 不同，需按实际 PHP-FPM 用户调整目录属主。

**根治方案：** 新版代码在启动时把五种 Laravel 生成缓存（服务、包、配置、路由、事件）放在 `shared/storage/framework/cache/bootstrap/vX.Y.Z/`。发行包中的 `releases/` 可以只读，且无需存在 `bootstrap/cache`。每个版本缓存独立，避免旧版生成文件跨版本残留；切换版本前检查私有缓存目录可写。诊断命令 `/www/server/php/84/bin/php <实际站点>/deploy/diagnose.php` 会显示实际缓存路径、`view` 服务绑定及相关写入状态。若升级后仍出现 HTTP 500，请提供最新时间的日志而不是此前旧日志。

## 目前功能与限制

- 已实现：单机管理员登录、文本/图片独立模型配置、可编辑提示词参考/自定义版、自动口播类型识别、品牌素材强制上传、普通资产实际图片生成及预览、后台检查更新与一键更新、MySQL 分镜生成任务与状态轮询、剧本项目管理、15/30 秒单镜限制、AI 分镜与台词时长估算、人物/场景/道具文本清单、镜头编辑与初版可灵/Seedance/Stable Diffusion 提示词、项目 JSON 导出。
- 尚未实现：自动把客户上传 Logo 合成到角色或产品图片中、声音参考和 Voice ID 管理、完整 Drama Skills 导入与执行、真实视频生成、任务队列。
- 创作流程参考 [雪风 AI 短剧工坊](https://github.com/oiuv/ai-short-drama)，技能方向参考 [Drama Skills](https://github.com/zenstory-ai/drama-skills)，当前 AIDrama 是独立 PHP 实现。

CI 对源码、Laravel、MySQL 5.7 迁移、单 ZIP 解压后全新安装及登录进行验证，不代表已经在你实际的宝塔/OpenResty 服务器上测试。首装请使用新建空数据库。MySQL 5.7 已停止常规安全维护，建议后续升级。
