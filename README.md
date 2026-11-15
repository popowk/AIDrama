# AIDrama · AI 剧本与分镜工作台

**运行环境**：宝塔 OpenResty + PHP 8.4 + MySQL 5.7.44；Blade + 原生 JavaScript，无需服务器安装 Node.js、Composer、Redis 或 Python。

## 简洁安装：只下载一个 ZIP

前往 [最新 GitHub Release](https://github.com/popowk/AIDrama/releases/latest)，**只下载 `AIDrama.zip`**。程序、PHP 依赖与图形化安装器已经整合在一起，不再有单独的 Installer.zip。

1. 在宝塔新建站点，PHP 版本设为 8.4，网站目录填写 **`/www/wwwroot/AIDrama/public`**（网站运行根目录为 public，不是 AIDrama，也不是 current/public）。启用 HTTPS。
2. 将 `AIDrama.zip` 上传到 `/www/wwwroot/AIDrama/` 并在该目录解压。解压后应存在 `/www/wwwroot/AIDrama/public/index.php` 和 `/www/wwwroot/AIDrama/releases/vX.Y.Z/`。
3. 在宝塔创建一个**空 MySQL 数据库**及独立账号，记下其账号和密码。
4. 通过 HTTPS 访问该网站。首页会显示 **AIDrama Setup** 安装向导；在宝塔文件管理器中打开 `/www/wwwroot/AIDrama/shared/setup-token.txt`，复制一次性口令到安装页面验证身份。
5. 安装页检查 PHP 8.4、所需扩展、目录和站点根路径，填写数据库信息及管理员密码，点击一键安装。安装会使用 ZIP 中的完整代码，不会再从 GitHub 下载第二份安装包。

**注意**：首次安装需启用 PHP 扩展 `fileinfo`、`curl`、`zip`、`mbstring`、`openssl`、`pdo_mysql` 等。PHP-FPM 进程应能写入 AIDrama 根目录及 `shared/` 和 `releases/`；宝塔 PHP 防跨站配置要允许站点 PHP 访问整个 AIDrama 目录。数据库建议限制为仅本机可访问。只对全新、空的 AIDrama 目录解压安装包，**不要把安装包覆盖已有站点**。

## 为什么还有 releases 和 current 目录？

对外的网站运行根目录始终是：

```text
/www/wwwroot/AIDrama/public
```

程序目录（从浏览器不能直接访问）：

```text
AIDrama/
├── public/                 # 唯一 Web 公开目录，包含入口和 CSS
├── bootstrap/installer.php # 安装逻辑，位于 Web 根目录之外
├── releases/
│   └── vX.Y.Z/            # 完整 Laravel 代码及 vendor
├── current -> releases/vX.Y.Z  # 内部版本指针，不是宝塔的网站根目录
└── shared/
    ├── .env               # 私有 APP_KEY、数据库配置
    ├── storage/            # 私有登录哈希、素材、日志
    └── install.lock        # 安装完成标记
```

`current` 仅用于升级与回滚，不再要求在宝塔网站路径中填写该目录。正式程序入口是一个轻量路由文件：未安装时加载网页安装向导；完成安装并加锁后只调用当前 Laravel 版本，安装入口不可再用。

不会创建 `public/storage` 软链接，也不会把用户素材、数据库备份、API Key、日志或 `.env` 放在公开目录。发布包也不含任何用户凭据。

### 宝塔伪静态

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

如宝塔已配置 Laravel 相同规则，不要重复添加。在网站根目录和 HTTPS 设置正确前，不要开放安装表单。

## 更新方式

在 GitHub `main` 提交代码后，GitHub Actions 会生成新的 `AIDrama.zip` 完整 Release。**首次安装只需一次下载**；后续可在宝塔计划任务中，每小时以网站用户执行：

```bash
su -s /bin/sh www -c '/www/server/php/84/bin/php /www/wwwroot/AIDrama/current/deploy/update.php apply'
```

更新器从 GitHub 最新 Release 下载完整包，只提取内部的新版本程序，不会覆盖 `public/index.php`、`shared/.env`、`shared/storage`、数据库或项目素材。更新使用 SHA256 校验、版本隔离及内部版本指针；升级涉及数据库迁移时自动停止，需要人工备份和审核。回滚仅恢复代码，不会自动回退数据库。

## 目前功能与限制

- 已实现：单机管理员登录、个人文本模型 API 设置、剧本项目管理、15/30 秒单镜限制、AI 分镜与台词时长估算、人物/场景/道具文本清单、镜头编辑与初版可灵/Seedance/Stable Diffusion 提示词、项目 JSON 导出。
- 尚未实现：实际角色三视图图片生成、声音参考和 Voice ID 管理、完整 Drama Skills 导入与执行、真实视频生成、任务队列。
- 创作流程参考 [雪风 AI 短剧工坊](https://github.com/oiuv/ai-short-drama)，技能方向参考 [Drama Skills](https://github.com/zenstory-ai/drama-skills)，当前 AIDrama 是独立 PHP 实现。

CI 对源码、Laravel、MySQL 5.7 迁移及部分安装页面进行验证，不代表已经在你实际的宝塔/OpenResty 服务器上测试。首装请使用新建空数据库。MySQL 5.7 已停止常规安全维护，建议后续升级。
