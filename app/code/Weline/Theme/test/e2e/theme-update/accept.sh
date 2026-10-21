#!/usr/bin/env bash
# 主题更新代次：一键验收（构建 → 切换 → 回滚 → 静态资源全 200 → 维护模式关闭）
#
# 用法：
#   BASE=https://p05113ef3.test.weline.com:9555 THEME=hanfu RESOLVE_HOST="p05113ef3.test.weline.com:9555:127.0.0.1" \
#     bash app/code/Weline/Theme/test/e2e/theme-update/accept.sh
#
# 退出码：0 = 全部通过；非 0 = 有断言失败（并打印失败项）
set -uo pipefail

BASE="${BASE:-https://p05113ef3.test.weline.com:9555}"
THEME="${THEME:-hanfu}"
RESOLVE_HOST="${RESOLVE_HOST:-}"
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36"
HOST="$(echo "$BASE" | sed -E 's#https?://##; s#[:/].*##')"

fail=0
ok()   { echo "  [ok] $*"; }
bad()  { echo "  [FAIL] $*"; fail=$((fail+1)); }
CURL_ARGS=(-sk --max-time 25 -A "$UA")
if [ -n "$RESOLVE_HOST" ]; then CURL_ARGS+=(--resolve "$RESOLVE_HOST"); fi
curl_bin() { curl "${CURL_ARGS[@]}" "$@"; }

echo "== 1. 构建新代次（不影响线上） =="
prefix_set() { curl_bin "$BASE/" | grep -oE '/static/[A-Za-z0-9_.-]+/' | sort -u; }
before="$(prefix_set)"
build_out="$(php bin/w theme:update build -t "$THEME" 2>&1)"
batch="$(echo "$build_out" | grep -oE 'tu-[0-9]{8}-[0-9]{6}-[a-z0-9]{6}' | head -1)"
if [ -z "$batch" ]; then
  echo "$build_out" | tail -20
  bad "构建失败：未拿到 batch_id"
  exit 1
fi
echo "  batch_id=${batch}"
echo "$build_out" | grep -q '构建完成且校验通过' && ok "构建校验通过" || bad "构建校验未通过"
after="$(prefix_set)"
[ "$before" = "$after" ] && ok "构建期线上静态前缀未变化" || bad "构建期线上静态前缀发生变化"

echo "== 2. 切换（promote） =="
prev_batch="$(php bin/w theme:update status -t "$THEME" 2>&1 | sed 's/\x1b\[[0-9;]*m//g' | sed -n 's/.*当前代次：\(tu-[0-9a-z-]*\).*/\1/p' | head -1)"
php bin/w theme:update promote "$batch" >/dev/null 2>&1 || bad "promote 失败"
refs=0
for _ in $(seq 1 5); do
  sleep 4
  page="$(curl_bin "$BASE/")"
  refs="$(echo "$page" | grep -c "/static/$batch/")"
  [ "$refs" -gt 0 ] && break
done
[ "$refs" -gt 0 ] && ok "页面已指向新代次静态前缀（$refs 处）" || bad "页面仍未使用新代次前缀"

echo "== 3. 静态资源全 200 =="
echo "$page" | grep -oE '(src|href)="[^"]*"' | sed 's/^[a-z]*="//; s/"$//' | grep -E '^/static/' | sort -u > /tmp/tu_urls.txt
non200=0
while IFS= read -r u; do
  c="$(curl_bin -o /dev/null -w '%{http_code}' "$BASE$u")"
  [ "$c" != "200" ] && { echo "    $c $u"; non200=$((non200+1)); }
done < /tmp/tu_urls.txt
[ "$non200" -eq 0 ] && ok "静态 URL 全部 200（$(wc -l < /tmp/tu_urls.txt) 个）" || bad "有 $non200 个静态 URL 非 200"

echo "== 4. 回滚 =="
if [ -n "$prev_batch" ] && [ "$prev_batch" != "$batch" ]; then
  php bin/w theme:update rollback -t "$THEME" >/dev/null 2>&1 || bad "rollback 失败"
  refs_prev=0
  for _ in $(seq 1 5); do
    sleep 4
    page="$(curl_bin "$BASE/")"
    refs_prev="$(echo "$page" | grep -c "/static/$prev_batch/")"
    [ "$refs_prev" -gt 0 ] && break
  done
  [ "$refs_prev" -gt 0 ] && ok "回滚后页面指回上一个代次（$refs_prev 处）" || bad "回滚后页面未指回上一个代次"
  php bin/w theme:update promote "$batch" >/dev/null 2>&1 || bad "回滚后切回失败"
  sleep 4
else
  ok "无上一个代次，跳过回滚断言"
fi

echo "== 5. 维护模式 =="
maint="$(php bin/w maintenance:status 2>&1)"
echo "$maint" | grep -qiE 'Maintenance mode:?\s*(Close|关闭|off)' && ok "维护模式为关闭" || bad "维护模式疑似开启"

echo "== 6. 主题扫描与单测 =="
audit_out="$(python3 app/code/Weline/Theme/bin/module-frontend-theme-audit.py 2>/dev/null | head -1)"
if echo "$audit_out" | grep -q 'P0=0'; then
  ok "主题扫描 P0=0（${audit_out}）"
  if ! echo "$audit_out" | grep -q 'hits=0'; then
    echo "  [WARN] 存在非 P0 命中（可能来自并发会话新增文件）：${audit_out}"
  fi
else
  bad "主题扫描出现 P0 命中：${audit_out}"
fi
vendor/bin/phpunit --no-coverage app/code/Weline/Theme/test/Unit/Service/ThemeUpdate/ >/tmp/tu_phpunit.txt 2>&1 \
  && ok "代次单测通过" || bad "代次单测失败（见 /tmp/tu_phpunit.txt）"

echo
if [ "$fail" -eq 0 ]; then
  echo "ALL PASS"
  exit 0
fi
echo "FAILED: $fail 项"
exit 1
