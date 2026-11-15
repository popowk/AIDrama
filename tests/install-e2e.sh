#!/usr/bin/env bash
set -euo pipefail
SMOKE="$RUNNER_TEMP/aidrama-install-e2e"
cp -a "$BUNDLE" "$SMOKE"
php -r '$p=new PDO("mysql:host=127.0.0.1;port=3306","root","aidrama-ci-only");$p->exec("CREATE DATABASE aidrama_install_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");'
php -S 127.0.0.1:18868 -t "$SMOKE/public" > "$RUNNER_TEMP/setup-server.log" 2>&1 &
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
  --data-urlencode 'admin_password=CI-test-password-strong-2026!' \
  --data-urlencode 'admin_confirm=CI-test-password-strong-2026!' \
  http://127.0.0.1:18868/ > "$RUNNER_TEMP/result.html"
if ! grep -qi 'Location: /login' "$RUNNER_TEMP/install.headers"; then
  echo "Installer did not redirect to login. Diagnostics:"
  tail -35 "$RUNNER_TEMP/result.html"
  tail -35 "$RUNNER_TEMP/setup-server.log"
  exit 1
fi
test -L "$SMOKE/current"
test -f "$SMOKE/shared/install.lock"
test -f "$SMOKE/shared/storage/app/private/admin.hash"
test ! -e "$SMOKE/shared/setup-token.txt"
test ! -e "$SMOKE/public/.env"
test ! -e "$SMOKE/public/storage"
curl -fsS http://127.0.0.1:18868/login > "$RUNNER_TEMP/login.html"
grep -q 'AIDrama' "$RUNNER_TEMP/login.html"
echo "E2E setup succeeds and private state stays outside public"
