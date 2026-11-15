# AIDrama · AI 影视创作工作台

AIDrama 是面向单用户的 PHP/Laravel 剧本、AI 分镜与资产规划平台。工作流程参考 [雪风 AI 短剧工坊](https://github.com/oiuv/ai-short-drama)，技能设计参考 [Drama Skills](https://github.com/zenstory-ai/drama-skills)。这是独立的 PHP 实现，没有复制上述项目的完整源码。

## 已完成（v0.1.0）

- 管理员登录（私有存储中的密码哈希）
- 剧本项目、画风、15/30 秒上限、AI 自动分镜及过长台词拆镜
- OpenAI 兼容文本 API（服务端加密保存密钥）
- 人物/场景/道具资产清单，编辑镜头及预计时长
- 可灵 Kling、Seedance、Stable Diffusion 提示词初步模板及项目 JSON 导出
- PHP 环境检测，GitHub Releases 安装包，安全的版本化更新和回滚

还未实现：实际图片/三视图生成、声音参考音频和 Voice ID、完整 Drama Skills 引擎、视频生成、后台异步任务；下一阶段继续补齐。当前分镜生成使用同步 API 请求，请确认 PHP-FPM 超时支持。

## 宝塔 OpenResty 安装（PHP 8.4 + MySQL 5.7.44）

无需 Node.js、Redis、Python 或 Supervisor；GitHub Actions 打包的 Release ZIP 已包含 vendor 依赖。

### 1. 环境检查

在宝塔 PHP 8.4 扩展管理里安装 fileinfo（你的检测日志中它缺失），并检查 ZIP 重复加载警告。运行：

    mkdir -p /www/wwwroot/AIDrama/deploy
    curl -fL https://raw.githubusercontent.com/popowk/AIDrama/main/deploy/preflight.php -o /www/wwwroot/AIDrama/deploy/preflight.php
    /www/server/php/84/bin/php /www/wwwroot/AIDrama/deploy/preflight.php

环境检查只读，不会自动安装扩展。日志记录的服务器架构为 x86_64 而非 ARM，请确认部署的是同一台服务器。

### 2. 初次安装

在 GitHub Actions 构建并发布正式 v0.1.0 Release 后运行：

    curl -fL https://raw.githubusercontent.com/popowk/AIDrama/main/deploy/update.php -o /www/wwwroot/AIDrama/deploy/update.php
    chown -R www:www /www/wwwroot/AIDrama
    su -s /bin/sh www -c '/www/server/php/84/bin/php /www/wwwroot/AIDrama/deploy/update.php check'
    su -s /bin/sh www -c '/www/server/php/84/bin/php /www/wwwroot/AIDrama/deploy/update.php apply'

首次 apply 会创建 shared/.env、shared/storage 和 releases/v0.1.0，然后因 APP_KEY 尚未设置而主动暂停切换。接下来：

1. 宝塔新建数据库 aidrama 及独立 MySQL 用户，在 /www/wwwroot/AIDrama/shared/.env 设置 DB_ 开头的连接参数、APP_URL、APP_ENV=production、APP_DEBUG=false；启用 HTTPS 后设 SESSION_SECURE_COOKIE=true。
2. 执行以下命令生成应用密钥，切勿在升级时重新生成：

       cd /www/wwwroot/AIDrama/releases/v0.1.0
       su -s /bin/sh www -c '/www/server/php/84/bin/php artisan key:generate --force'

3. 再执行 apply，然后初始化数据库和管理员密码：

       su -s /bin/sh www -c '/www/server/php/84/bin/php /www/wwwroot/AIDrama/deploy/update.php apply'
       cd /www/wwwroot/AIDrama/current
       su -s /bin/sh www -c '/www/server/php/84/bin/php artisan migrate --force'
       su -s /bin/sh www -c '/www/server/php/84/bin/php artisan aidrama:set-password'

4. 在宝塔把网站根目录设为 /www/wwwroot/AIDrama/current/public，PHP 版本选 8.4，伪静态设置为：

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

5. 确保 PHP-FPM 用户可读 shared/.env 和 vendor，可写 shared/storage 和 releases/*/bootstrap/cache。最好使用与 PHP-FPM 兼容的非 root 账户运行部署脚本，避免 0600 权限阻止读密钥。

访问站点并用刚设置的管理员密码登录。

### 3. GitHub 自动更新

每次代码推送到 main 后，GitHub Actions 会自动发布新的正式 Release，无需手动修改版本号。VERSION 只用来设定主版本和次版本，补丁版本由 Actions 构建序号自动递增；发布包自带全部 PHP vendor，不需在宝塔服务器运行 Composer/npm。

宝塔「计划任务」添加 Shell 每小时执行：

    su -s /bin/sh www -c '/www/server/php/84/bin/php /www/wwwroot/AIDrama/current/deploy/update.php apply'

检查新版本：

    su -s /bin/sh www -c '/www/server/php/84/bin/php /www/wwwroot/AIDrama/current/deploy/update.php check'

回滚代码：

    su -s /bin/sh www -c '/www/server/php/84/bin/php /www/wwwroot/AIDrama/current/deploy/update.php rollback'

更新脚本使用 GitHub 官方 Release、SHA256、ZIP 路径验证、独立版本目录及原子软链接切换。不会覆盖 shared/.env、shared/storage 或 MySQL 数据。数据库迁移文件变化时默认停止自动升级；备份数据库并审核迁移后可手动执行 apply --allow-migrations（此命令不会自动迁移数据库，须手动执行迁移）。

注意：SHA256 提供完整性验证，但如果 GitHub 账户本身失陷，它不能提供独立可信签名。请启用 GitHub 双因素认证。旧 MySQL 5.7 已结束官方维护，需限制其网络暴露。站点 Web 根目录必须指向 current/public，不能指向仓库根目录。

### 开发与授权

框架 Laravel 13 / PHP 8.4 / MySQL 5.7 兼容模式。前端 Blade + 原生 JS，无 Vite/Node。技能目录 skills/ 目前为 AIDrama 自编的初版说明，不会擅自执行互联网上的未知 Python 脚本。应用首次发布前仍需通过 GitHub Actions 与真实宝塔环境测试。
