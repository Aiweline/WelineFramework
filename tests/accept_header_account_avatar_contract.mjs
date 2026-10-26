// 无浏览器：按 account-session.js 的查询契约，静态校验线上渲染出的账号位。
// 断言点直接取自 syncHeaderAccountChrome / applyHeaderSignedIn / paintHeaderAccountAvatars 的选择器。
import fs from 'node:fs';

const html = fs.readFileSync(process.argv[2], 'utf8');
const fails = [];
const ok = (cond, msg) => { if (!cond) fails.push(msg); };

// 1) 根：document.querySelectorAll('[data-w-header-account="1"]')
const roots = html.match(/data-w-header-account="1"/g) || [];
ok(roots.length === 1, `root 数量应为 1，实际 ${roots.length}`);

// 2) 双壳
ok(/data-account-shell="guest"/.test(html), '缺少 [data-account-shell="guest"]');
ok(/data-account-shell="signed-in"[^>]*hidden/.test(html), '[data-account-shell="signed-in"] 必须 SSR 默认 hidden');

// 3) 头像钩子（paintHeaderAccountAvatars）
ok(/data-account-avatar-wrap/.test(html), '缺少 [data-account-avatar-wrap]');
ok(/<img[^>]*data-account-avatar[^>]*hidden/.test(html), '<img data-account-avatar> 必须默认 hidden（等 account.current）');
ok(/<img[^>]*data-account-avatar[^>]*width="28"[^>]*height="28"/.test(html), '头像 img 需 width/height=28 占位防 CLS');
ok(/data-account-avatar-fallback/.test(html), '缺少 [data-account-avatar-fallback]');
ok(/data-account-avatar-initial[^>]*hidden/.test(html), '[data-account-avatar-initial] 必须默认 hidden');

// 4) 懒加载与鉴权态
ok(/data-weline-load="api,account"/.test(html), '缺少 data-weline-load="api,account"（account 模块不会加载）');
ok(/data-weline-load-when="idle"/.test(html), '应为 idle 加载，避免阻塞首屏');
ok(/data-auth-state="guest"/.test(html), 'SSR 必须 data-auth-state="guest"（FPC 不得带会话）');
ok(/data-header-account-ssr="guest-v1"/.test(html), '缺少 guest-v1 版本标');

// 5) 未登录态不能被提前隐藏
ok(!/data-account-shell="guest"[^>]*hidden/.test(html), '未登录壳不能 hidden');

// 6) 链接可回退（无 JS 也能进账户页）
ok(/class="ca-icon-link ca-icon-link--account"[^>]*href="[^"]*customer\/account"/.test(html), '账号链接 href 丢失');

if (fails.length) {
  console.log('CONTRACT FAIL');
  fails.forEach((f) => console.log(' - ' + f));
  process.exit(1);
}
console.log('CONTRACT PASS  (root=1, guest/signed-in 双壳, 6 个头像钩子, lazy=api,account, SSR=guest)');
