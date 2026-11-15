# AIDrama · AI 影视创作工作台

基于 Laravel 13 + PHP 8.4 + MySQL 5.7.44 的单机 AI 分镜创作平台。前端 Blade + 原生 JavaScript，运行不需要 Node.js。设计参考雪风 AI 短剧工坊 (https://github.com/oiuv/ai-short-drama)，Skills 方向参考 Drama Skills (https://github.com/zenstory-ai/drama-skills)；当前是独立实现，并非原版源码改写。

## 已实现

- 单机管理员密码登录；API Key 在服务器端加密保存
- 剧本项目、风格、最长 15/30 秒的单镜头上限
- OpenAI 兼容文本 API、AI 分镜与时长估算、超长台词拆镜
- 人物/场景/道具资产清单、镜头编辑、项目 JSON 导出
- 可灵、Seedance、Stable Diffusion 提示词的初版模板
- 网页安装向导、环境检测、版本化部署和 GitHub Release 自动更新

还未实现实际三视图出图、真实声音资产/Voice ID、完整 Drama Skills 引擎、图片与视频生成、异步任务。镜头时长目前是规划估算，而不是根据真实配音测量。

## 宝塔首次安装：图形化向导

服务器要求：OpenResty + PHP 8.4 + MySQL 5.7。PHP 必需扩展为 fileinfo、curl、openssl、mbstring、pdo_mysql、zip 等，安装页面会自动检测。无需服务器安装 Node.js、Composer、Python、Redis、Supervisor、Docker。

### 只需首次准备

1. 在宝塔添加网站，绑定域名并启用 HTTPS，选择 PHP 8.4。网站根目录必须指向：
   
       /www/wwwroot/AIDrama/current/public

   不能指向 AIDrama、current 或 releases 的上级目录。建议 PHP 运行用户使用宝塔默认的 www，并确保 www 对 AIDrama 安装目录有写权限。如果宝塔的 PHP 防跨站 open_basedir 限制只能访问 public，请调整为允许访问整个 AIDrama 安装目录，否则安装器无法创建共享私有数据。

2. 在宝塔 MySQL 管理页面，创建一个空数据库和独立用户，记下数据库名、账号和密码。不要使用 MySQL root 账号作为网站账号。

3. 打开 [最新正式版本](https://github.com/popowk/AIDrama/releases/latest)，下载 **AIDrama-Installer.zip**，通过宝塔文件管理器上传到 /www/wwwroot/AIDrama 并解压到此目录，确认存在：

       /www/wwwroot/AIDrama/current/public/index.php

   这个小安装包只有一次性启动网页的代码。**它只能解压到尚未安装程序的空目录，不能覆盖已有 AIDrama 网站。**

4. 浏览器使用 HTTPS 访问网站首页，即可进入 AIDrama Setup 向导。首次访问会在下面的位置生成一次性安装口令（这个口令文件无法通过网址访问）：

       /www/wwwroot/AIDrama/shared/setup-token.txt

   用宝塔文件管理器打开此文件，复制内容到网页的「一次性安装口令」字段。验证后才能显示服务器检测和安装表单。

5. 按网页提示检查 PHP 扩展、网站文档根目录和可写权限；输入 MySQL 地址、端口、库名、用户名、密码、网站 HTTPS 地址与新的管理员密码（至少 12 位），点击「检查数据库并一键安装」。

网页安装器将自动检测数据库连接、下载最新 GitHub Release、校验 SHA256、解压完整版 PHP 程序、生成 APP_KEY、初始化 MySQL 表、保存管理员密码的安全哈希，并切换到正式版本。安装成功会自动跳转到 /login，并删除一次性安装口令和公开的启动脚本。**不需要在宝塔终端运行 Artisan 命令，不需要手工编辑 .env。**

### 宝塔伪静态

建议在宝塔网站设置中使用 Laravel 兼容的伪静态；如果尚未有等效规则，可配置：

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

还可参考仓库的 deploy/nginx-security.conf.example，但不要在已有宝塔 PHP 规则上直接添加冲突的 location 块。

## 安全：公开目录只有必要的网页入口

网页根目录必须为：

    /www/wwwroot/AIDrama/current/public

下面这些文件**全部在 public 之外**，不会成为网页可直接下载的文件：

- shared/.env：Laravel APP_KEY、数据库连接，文件权限 0600
- shared/storage/app/private：登录密码哈希、后续 API 密钥和私有资产
- shared/storage/logs：运行日志
- shared/setup-token.txt：安装时的一次性口令，完成后删除
- releases：完整应用代码和 vendor 依赖，不能作为网站根目录
- 数据库、备份：不要上传到公开的网站目录

应用不创建 public/storage 软链接，更新器和 GitHub Actions 会拒绝敏感内容出现在发布包的公开目录中。管理端 HTTP 响应启用防嵌入、禁止 MIME 猜测和不缓存等安全响应头。

**注意：敏感配置一定需要存储在服务器磁盘上，不能凭空消失。这里采用的是“物理保存于 public 之外 + 限制权限”，而不是把秘密放在公开目录后只隐藏链接。** 如果误把宝塔网站根目录指向 AIDrama 项目上级目录，仍会造成严重泄露风险，因此安装器会检查正确的网站根目录才允许继续。

## GitHub 自动更新（一次性配置）

代码每次推送 main，GitHub Actions 会运行 PHP 检查、Laravel 启动验证、SQLite 测试迁移，以及 MySQL 5.7 迁移检查；通过后生成一个带 PHP vendor 的正式 Release。以后宝塔服务器不需要运行 Composer。

首次安装完毕后，在宝塔「计划任务」新增每小时执行的 Shell 任务：

    su -s /bin/sh www -c '/www/server/php/84/bin/php /www/wwwroot/AIDrama/current/deploy/update.php apply'

更新脚本会检查 GitHub Release，用 SHA256 验证包、保留 shared/.env、shared/storage 和 MySQL，采用版本目录及软链接切换。数据库迁移发生变更时自动暂停，不会擅自改动数据表；需备份审核后手动迁移。代码回滚命令：

    su -s /bin/sh www -c '/www/server/php/84/bin/php /www/wwwroot/AIDrama/current/deploy/update.php rollback'

如果你不想添加自动计划任务，也可以在宝塔终端手工执行上面相同的更新命令；目前管理员后台尚未提供一键更新按钮。

## 测试状态及限制

GitHub Actions 的 PHP 语法检查、Laravel 命令启动和测试数据库迁移，不等同于已经在你实际的宝塔/OpenResty 系统中完成全流程安装测试。首次上线请用新建空库先测试，确保 PHP-FPM 超时、HTTPS 和站点目录权限设置正确。MySQL 5.7 已结束常规安全维护，数据库建议仅允许内网/本机访问。
