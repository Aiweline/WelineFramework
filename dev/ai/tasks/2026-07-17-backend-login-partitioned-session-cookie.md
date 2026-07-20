# 后台登录 Partitioned Session Cookie 修复

- 日期：2026-07-17
- 状态：服务端验证完成，等待用户浏览器手动确认
- 实例：`ai-test-payment-env-20260717-1515`
- 地址：`https://p05113ef3.weline.test:9811/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/admin/login`

## 现象与根因

浏览器提交正确的 `admin/admin` 后，认证与 ACL 写入均成功，但 302 跳转后马上回到
`admin/login?no_access_reason=not_logged_in`。登录页和登录成功响应原先都使用
`SameSite=Lax`；嵌入式浏览器没有在导航间保留该 Session Cookie。

第一处修复让 HTTPS 非标准端口的 WLS 验收实例使用 CHIPS Cookie。运行时复查又发现
`Weline_Admin::Login` 在成功登录后通过 `HeaderCollector` 硬编码 `Lax`，覆盖了统一策略。

## 修改

- `SessionFactory`：常规 80/443 继续使用 `SameSite=Lax`；HTTPS 非标准端口自动使用
  `SameSite=None; Partitioned`；可通过 `session.cookie_partitioned` 显式开关。
- `Admin Login`：普通密码登录与扩展登录统一调用
  `persistBackendLoginSessionCookie()`，标准 Session 通过当前 `SessionStrategy::setCookie()`
  重新签发 Cookie，不再硬编码 `Lax`。
- `Secure`、`HttpOnly`、CSRF、ACL 和随机后台路径保持不变。

## 验证

- PHP lint：`SessionFactory.php`、`Login.php` 均通过。
- 差异检查：通过。
- 登录页响应：`WELINE_SESSID; Secure; HttpOnly; SameSite=None; Partitioned`。
- 登录 POST：HTTP 302；Session Cookie 仍为 `SameSite=None; Partitioned`。
- 带新 Session 请求当前 scope 的 Payment 页面：HTTP 200。
- `auth.log`：记录 `login_post_success`、`login_post_redirect` 与随后
  `payment/backend/method/index`；未再出现紧随其后的 `not_logged_in`。
- 浏览器自动验收：Chrome 控制扩展可列出目标标签页，但导航、截图与 DOM 操作持续超时；
  未读取或写入浏览器 Cookie，需用户在保留的 9811 实例手动提交一次登录确认。

## 实例清理

用户确认后执行：

```bash
php bin/w server:stop ai-test-payment-env-20260717-1515
```
