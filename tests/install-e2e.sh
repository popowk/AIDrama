#!/usr/bin/env bash
set -euo pipefail
trap 'echo "Install E2E failed around line $LINENO" >&2' ERR
SMOKE="$RUNNER_TEMP/drama.tmd.pet"
cp -a "$BUNDLE" "$SMOKE"
# Must start with a pristine site; the ZIP may never ship any private state.
test ! -e "$SMOKE/shared"
test ! -e "$SMOKE/current"
test ! -e "$SMOKE/public/.env"
test ! -e "$SMOKE/public/storage"
php -r '$p=new PDO("mysql:host=127.0.0.1;port=3306","root","aidrama-ci-only");$p->exec("CREATE DATABASE aidrama_install_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");'
php -d disable_functions=symlink -S 127.0.0.1:18868 -t "$SMOKE/public" > "$RUNNER_TEMP/setup-server.log" 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
sleep 2
COOKIE="$RUNNER_TEMP/setup-cookies"
curl -fsS -c "$COOKIE" http://127.0.0.1:18868/ > "$RUNNER_TEMP/first.html"
grep -q '验证安装身份' "$RUNNER_TEMP/first.html"
test -s "$SMOKE/shared/setup-token.txt"
TOKEN="$(cat "$SMOKE/shared/setup-token.txt")"
CSRF="$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$RUNNER_TEMP/first.html" | head -1)"
test -n "$CSRF"
curl -fsS -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode "csrf=$CSRF" --data-urlencode "action=unlock" \
  --data-urlencode "setup_token=$TOKEN" \
  http://127.0.0.1:18868/ > "$RUNNER_TEMP/unlocked.html"
grep -q '网站与数据库配置' "$RUNNER_TEMP/unlocked.html"
CSRF="$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$RUNNER_TEMP/unlocked.html" | head -1)"
test -n "$CSRF"
curl -sS --max-time 150 -D "$RUNNER_TEMP/install.headers" \
  -b "$COOKIE" -c "$COOKIE" \
  --data-urlencode "csrf=$CSRF" --data-urlencode "action=install" \
  --data-urlencode 'app_url=http://127.0.0.1:18868' \
  --data-urlencode 'db_host=127.0.0.1' --data-urlencode 'db_port=3306' \
  --data-urlencode 'db_name=aidrama_install_e2e' --data-urlencode 'db_user=root' \
  --data-urlencode 'db_password=aidrama-ci-only' \
  --data-urlencode 'admin_password=7' \
  --data-urlencode 'admin_confirm=7' \
  http://127.0.0.1:18868/ > "$RUNNER_TEMP/result.html"
if ! grep -qi 'Location: /login' "$RUNNER_TEMP/install.headers"; then
  echo "Installer did not redirect to login. Diagnostics:"
  tail -35 "$RUNNER_TEMP/result.html"
  tail -35 "$RUNNER_TEMP/setup-server.log"
  exit 1
fi
test ! -e "$SMOKE/current"
test -f "$SMOKE/shared/active-version"
test "$(cat "$SMOKE/shared/active-version")" = "$(ls "$SMOKE/releases" | sort -V | tail -n1)"
test -f "$SMOKE/shared/install.lock"
test -s "$SMOKE/shared/.env"
grep -q '^APP_KEY=base64:' "$SMOKE/shared/.env"
test -f "$SMOKE/shared/storage/app/private/admin.hash"
grep -q '^sha256' "$SMOKE/shared/storage/app/private/admin.hash"
test ! -e "$SMOKE/shared/setup-token.txt"
test ! -e "$SMOKE/public/.env"
test ! -e "$SMOKE/public/storage"
test ! -e "$SMOKE/releases/$(cat "$SMOKE/shared/active-version")/.env"
test ! -e "$SMOKE/releases/$(cat "$SMOKE/shared/active-version")/storage"
curl -fsS -b "$COOKIE" -c "$COOKIE" http://127.0.0.1:18868/login > "$RUNNER_TEMP/login.html"
grep -q 'AIDrama' "$RUNNER_TEMP/login.html"
TOKEN_LOGIN="$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$RUNNER_TEMP/login.html" | head -1)"
test -n "$TOKEN_LOGIN"
curl -sS -b "$COOKIE" -c "$COOKIE" -D "$RUNNER_TEMP/login.headers" \
  --data-urlencode "_token=$TOKEN_LOGIN" --data-urlencode 'password=7' \
  http://127.0.0.1:18868/login > "$RUNNER_TEMP/login-response.html"
if ! grep -qi 'location: http://127.0.0.1:18868' "$RUNNER_TEMP/login.headers" &&
   ! grep -qi 'location: /' "$RUNNER_TEMP/login.headers"; then
  echo 'Short admin password did not authenticate'
  tail -40 "$RUNNER_TEMP/setup-server.log"
  cat "$RUNNER_TEMP/login.headers"
  exit 1
fi
# Regression: Laravel 13 cached config MUST keep all core service providers.
# Previous AIDrama config/app.php omitted app.providers, causing HTTP 500
# with "Target class [view] does not exist" when config:cache was present.
ACTIVE="$(cat "$SMOKE/shared/active-version")"
APP_DIR="$SMOKE/releases/$ACTIVE"
PRIVATE_BOOTSTRAP="$SMOKE/shared/storage/framework/cache/bootstrap/$ACTIVE"
# ZIPs exclude bootstrap/cache entirely: release code is non-writable on
# production hosts, but a page must still boot using private shared cache.
test ! -d "$APP_DIR/bootstrap/cache"
test -d "$PRIVATE_BOOTSTRAP"
chmod -R a-w "$APP_DIR/bootstrap"
curl -fsS -b "$COOKIE" http://127.0.0.1:18868/login > "$RUNNER_TEMP/login-readonly-release.html"
grep -q 'AIDrama' "$RUNNER_TEMP/login-readonly-release.html"
test -s "$PRIVATE_BOOTSTRAP/packages.php"
test -s "$PRIVATE_BOOTSTRAP/services.php"
echo "Read-only release with no bootstrap/cache booted successfully"
# Restore CI fixture permissions so later delete/recovery test can unlink it.
chmod -R u+w "$APP_DIR/bootstrap"
(cd "$APP_DIR" && php artisan config:cache)
php -r '$cfg=require $argv[1];$ps=$cfg["app"]["providers"]??[];if(!in_array(\Illuminate\View\ViewServiceProvider::class,$ps,true)||!in_array(\Illuminate\Session\SessionServiceProvider::class,$ps,true)){fwrite(STDERR,"Missing Laravel default providers in config cache\n");exit(1);}' "$PRIVATE_BOOTSTRAP/config.php"
curl -fsS -b "$COOKIE" http://127.0.0.1:18868/login > "$RUNNER_TEMP/login-with-cached-config.html"
grep -q 'AIDrama' "$RUNNER_TEMP/login-with-cached-config.html"
(cd "$APP_DIR" && php artisan config:clear)
# Confirm both model categories and the authenticated update center are reachable.
curl -fsS -b "$COOKIE" http://127.0.0.1:18868/settings > "$RUNNER_TEMP/models.html"
grep -q '文本模型' "$RUNNER_TEMP/models.html"
grep -q '图片模型' "$RUNNER_TEMP/models.html"
curl -fsS -b "$COOKIE" http://127.0.0.1:18868/updates > "$RUNNER_TEMP/updates.html"
grep -q '检查更新' "$RUNNER_TEMP/updates.html"
grep -q '一键更新' "$RUNNER_TEMP/updates.html"
grep -q '提示词管理' "$RUNNER_TEMP/models.html"
grep -q 'Seedance' "$RUNNER_TEMP/models.html"
grep -q '可灵 Kling' "$RUNNER_TEMP/models.html"
# Exercise actual project Blade rendering and form validation, not just route listing.
curl -fsS -b "$COOKIE" http://127.0.0.1:18868/ > "$RUNNER_TEMP/dashboard.html"
PROJECT_CSRF="$(sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p' "$RUNNER_TEMP/dashboard.html" | head -1)"
test -n "$PROJECT_CSRF"
curl -sS -b "$COOKIE" -c "$COOKIE" -D "$RUNNER_TEMP/project-create.headers" \
  --data-urlencode "_token=$PROJECT_CSRF" \
  --data-urlencode 'name=AI 口播创作测试' \
  --data-urlencode 'script=主持人站在摄像头前介绍产品。' \
  --data-urlencode 'style=3D国风动画' \
  --data-urlencode 'shot_limit=15' \
  --data-urlencode 'script_mode=mixed' \
  http://127.0.0.1:18868/projects > "$RUNNER_TEMP/project-create.html"
PROJECT_URL="$(sed -n 's/^[Ll]ocation: \(.*\/projects\/[0-9]*\).*/\1/p' "$RUNNER_TEMP/project-create.headers" | head -1 | tr -d '\r')"
test -n "$PROJECT_URL"
curl -fsS -b "$COOKIE" "$PROJECT_URL" > "$RUNNER_TEMP/project-details.html"
grep -q '创作形式' "$RUNNER_TEMP/project-details.html"
grep -q '文案＋口播' "$RUNNER_TEMP/project-details.html"
grep -q '资产清单' "$RUNNER_TEMP/project-details.html"
# Verify private client-product image upload, session gate, and preview.
PROJECT_ID="${PROJECT_URL##*/}"
ASSET_ID="$(php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=aidrama_install_e2e","root","aidrama-ci-only");$s=$p->prepare("INSERT INTO assets (project_id,type,name,description,source_kind,image_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())");$s->execute([(int)$argv[1],"prop","客户Logo","原始商标图","upload","pending"]);echo $p->lastInsertId();' "$PROJECT_ID")"
test -n "$ASSET_ID"
php -r '$im=imagecreatetruecolor(3,3);imagepng($im,$argv[1]);imagedestroy($im);' "$RUNNER_TEMP/client-logo.png"
curl -sS -b "$COOKIE" -c "$COOKIE" -D "$RUNNER_TEMP/logo-upload.headers" \
  -F "_token=$PROJECT_CSRF" -F "image=@$RUNNER_TEMP/client-logo.png;type=image/png" \
  "http://127.0.0.1:18868/projects/$PROJECT_ID/assets/$ASSET_ID/upload" \
  -o "$RUNNER_TEMP/logo-upload.html"
grep -qi '^Location:' "$RUNNER_TEMP/logo-upload.headers"
curl -fsS -b "$COOKIE" "http://127.0.0.1:18868/projects/$PROJECT_ID/assets/$ASSET_ID/file" -o "$RUNNER_TEMP/private-logo.png"
test -s "$RUNNER_TEMP/private-logo.png"
# Compact cached private thumbnails must not expose assets anonymously.
curl -fsS -b "$COOKIE" "http://127.0.0.1:18868/projects/$PROJECT_ID/assets/$ASSET_ID/thumbnail" -o "$RUNNER_TEMP/client-logo-thumb"
php -r '$i=getimagesize($argv[1]);if(!$i || $i[0]>320 || $i[1]>320)exit(1);' "$RUNNER_TEMP/client-logo-thumb"
THUMB_ANON_CODE="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:18868/projects/$PROJECT_ID/assets/$ASSET_ID/thumbnail")"
test "$THUMB_ANON_CODE" = "302"
# Actual download endpoint sets server-enforced attachment and a UTF-8 asset
# title (not a random 32-character storage name).
curl -fsS -b "$COOKIE" -D "$RUNNER_TEMP/logo-download.headers" \
  "http://127.0.0.1:18868/projects/$PROJECT_ID/assets/$ASSET_ID/download" \
  -o "$RUNNER_TEMP/client-logo-download"
cmp "$RUNNER_TEMP/private-logo.png" "$RUNNER_TEMP/client-logo-download"
grep -qi '^content-disposition: attachment' "$RUNNER_TEMP/logo-download.headers"
grep -qi 'filename\*=utf-8' "$RUNNER_TEMP/logo-download.headers"
DOWNLOAD_ANON_CODE="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:18868/projects/$PROJECT_ID/assets/$ASSET_ID/download")"
test "$DOWNLOAD_ANON_CODE" = "302"
FILE_ANON_CODE="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:18868/projects/$PROJECT_ID/assets/$ASSET_ID/file")"
test "$FILE_ANON_CODE" = "302"
curl -fsS -b "$COOKIE" "$PROJECT_URL" > "$RUNNER_TEMP/project-with-logo.html"
grep -q '已上传真实参考图' "$RUNNER_TEMP/project-with-logo.html"
grep -q 'asset-download-button' "$RUNNER_TEMP/project-with-logo.html"
grep -q 'asset-lightbox' "$RUNNER_TEMP/project-with-logo.html"
grep -q '/thumbnail' "$RUNNER_TEMP/project-with-logo.html"
echo "Private thumbnail, modal preview and UTF-8 asset-name download passed"
# Exercise authenticated AI-image endpoint and ensure its error is machine-readable.
SCENE_ID="$(php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=aidrama_install_e2e","root","aidrama-ci-only");$s=$p->prepare("INSERT INTO assets (project_id,type,name,description,source_kind,image_status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())");$s->execute([(int)$argv[1],"scene","普通庭院","空旷的庭院","generate","pending"]);echo $p->lastInsertId();' "$PROJECT_ID")"
curl -fsS -b "$COOKIE" "$PROJECT_URL" > "$RUNNER_TEMP/project-with-scene.html"
grep -q "data-generate-url=\"/projects/$PROJECT_ID/assets/$SCENE_ID/generate\"" "$RUNNER_TEMP/project-with-scene.html"
SCENE_HTTP="$(curl -sS -b "$COOKIE" -H "X-CSRF-TOKEN: $PROJECT_CSRF" -H 'Accept: application/json' \
  -X POST "http://127.0.0.1:18868/projects/$PROJECT_ID/assets/$SCENE_ID/generate" \
  -o "$RUNNER_TEMP/scene-generation-error.json" -w '%{http_code}')"
test "$SCENE_HTTP" = "422"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(($j["status"]??"")!=="error"||!str_contains($j["error"]??"","图片")||!str_contains($j["error"]??"","编号"))exit(1);' "$RUNNER_TEMP/scene-generation-error.json"
echo "Image-generation request diagnostics and route-generated URLs passed"



curl -fsS -b "$COOKIE" http://127.0.0.1:18868/updates/status > "$RUNNER_TEMP/update-state.json"
grep -q 'idle' "$RUNNER_TEMP/update-state.json"
php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=aidrama_install_e2e","root","aidrama-ci-only");$c=$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=\"generation_jobs\"")->fetchColumn();if((int)$c!==1)exit(1);'
# Simulate a release generated by an old AIDrama version whose config cache
# lacks app.providers. The new bootstrap must self-heal this generated cache.
LEGACY_CACHE_VERSION="v97.97.97"
cp -a "$APP_DIR" "$SMOKE/releases/$LEGACY_CACHE_VERSION"
printf '%s\n' "$LEGACY_CACHE_VERSION" > "$SMOKE/releases/$LEGACY_CACHE_VERSION/VERSION"
LEGACY_DIR="$SMOKE/releases/$LEGACY_CACHE_VERSION"
(cd "$LEGACY_DIR" && php artisan config:cache)
php -r '$f=$argv[1];$config=require $f;unset($config["app"]["providers"]);file_put_contents($f,"<?php return ".var_export($config,true).";\n");' "$SMOKE/shared/storage/framework/cache/bootstrap/$LEGACY_CACHE_VERSION/config.php"
printf '%s\n' "$LEGACY_CACHE_VERSION" > "$SMOKE/shared/active-version"
curl -fsS -b "$COOKIE" http://127.0.0.1:18868/login > "$RUNNER_TEMP/legacy-cache-recovery.html"
grep -q 'AIDrama' "$RUNNER_TEMP/legacy-cache-recovery.html"
test ! -f "$SMOKE/shared/storage/framework/cache/bootstrap/$LEGACY_CACHE_VERSION/config.php"
printf '%s\n' "$ACTIVE" > "$SMOKE/shared/active-version"
# The newest unapproved release must not bypass the active version marker.
OLD_VERSION="$(cat "$SMOKE/shared/active-version")"
RECOVERY_VERSION="v99.99.99"
INCOMPLETE_VERSION="v100.0.0"
cp -a "$SMOKE/releases/$OLD_VERSION" "$SMOKE/releases/$RECOVERY_VERSION"
printf '%s\n' "$RECOVERY_VERSION" > "$SMOKE/releases/$RECOVERY_VERSION/VERSION"
mkdir -p "$SMOKE/releases/$INCOMPLETE_VERSION"
printf '%s\n' "$INCOMPLETE_VERSION" > "$SMOKE/releases/$INCOMPLETE_VERSION/VERSION"
curl -fsS -b "$COOKIE" http://127.0.0.1:18868/updates > "$RUNNER_TEMP/updates-before-recovery.html"
grep -F ">${OLD_VERSION}</strong>" "$RUNNER_TEMP/updates-before-recovery.html"
# Simulate accidental deletion of the active release.
rm -rf -- "$SMOKE/releases/$OLD_VERSION"
curl -fsS -b "$COOKIE" http://127.0.0.1:18868/login > "$RUNNER_TEMP/login-after-recovery.html"
grep -q 'AIDrama' "$RUNNER_TEMP/login-after-recovery.html"
test "$(cat "$SMOKE/shared/active-version")" = "$RECOVERY_VERSION"
curl -fsS -b "$COOKIE" http://127.0.0.1:18868/updates > "$RUNNER_TEMP/updates-after-recovery.html"
grep -F ">${RECOVERY_VERSION}</strong>" "$RUNNER_TEMP/updates-after-recovery.html"
# Force a PHP startup Error and verify a visible safe diagnostic response.
# Never leave broken code in the test release after the assertion.
# Use a new release path that PHP has never executed, avoiding a cached
# opcache copy of the previously visited v99 entry point.
FAULT_VERSION="v98.98.98"
cp -a "$SMOKE/releases/$RECOVERY_VERSION" "$SMOKE/releases/$FAULT_VERSION"
printf '%s\n' "$FAULT_VERSION" > "$SMOKE/releases/$FAULT_VERSION/VERSION"
ENTRY="$SMOKE/releases/$FAULT_VERSION/public/index.php"
printf '<?php throw new RuntimeException("CI startup failure");\n' > "$ENTRY"
printf '%s\n' "$FAULT_VERSION" > "$SMOKE/shared/active-version"
HTTP_CODE="$(curl -sS -o "$RUNNER_TEMP/startup-error.html" -w '%{http_code}' http://127.0.0.1:18868/login)"
echo "Simulated PHP startup response HTTP $HTTP_CODE"
if ! test "$HTTP_CODE" = "500" ||
   ! grep -q 'AIDrama 启动失败' "$RUNNER_TEMP/startup-error.html" ||
   ! test -s "$SMOKE/shared/storage/logs/bootstrap-error.log"; then
  echo 'Startup error response test failed; diagnostics follow:'
  head -c 900 "$RUNNER_TEMP/startup-error.html" || true
  tail -25 "$RUNNER_TEMP/setup-server.log" || true
  exit 1
fi
printf '%s\n' "$RECOVERY_VERSION" > "$SMOKE/shared/active-version"
echo "E2E install, stale-release recovery and safe PHP startup error diagnostics passed"
