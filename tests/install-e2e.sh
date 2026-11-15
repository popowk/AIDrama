#!/usr/bin/env bash
set -euo pipefail
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
# Confirm both model categories and the authenticated update center are reachable.
curl -fsS -b "$COOKIE" http://127.0.0.1:18868/settings > "$RUNNER_TEMP/models.html"
grep -q '文本模型' "$RUNNER_TEMP/models.html"
grep -q '图片模型' "$RUNNER_TEMP/models.html"
curl -fsS -b "$COOKIE" http://127.0.0.1:18868/updates > "$RUNNER_TEMP/updates.html"
grep -q '检查更新' "$RUNNER_TEMP/updates.html"
grep -q '一键更新' "$RUNNER_TEMP/updates.html"
curl -fsS -b "$COOKIE" http://127.0.0.1:18868/updates/status > "$RUNNER_TEMP/update-state.json"
grep -q 'idle' "$RUNNER_TEMP/update-state.json"
php -r '$p=new PDO("mysql:host=127.0.0.1;dbname=aidrama_install_e2e","root","aidrama-ci-only");$c=$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=\"generation_jobs\"")->fetchColumn();if((int)$c!==1)exit(1);'
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
