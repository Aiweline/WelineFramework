# 后台登录 Session Cookie 丢失排查

- 日期：2026-07-20
- 状态：已修复并清理埋点
- 实例：`ai-test-login-debug-38d697`（验证用，可停）
- 地址：`https://p05113ef3.weline.test:11328/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/admin/login`

## 根因

1. HTTPS 非标准端口 Session Cookie 需 `SameSite=None; Partitioned`，且须在请求时按 authority/端口解析。
2. HTTP/2 多条 Cookie 头被错误用逗号拼接，导致 `WELINE_SESSID_11328` 落入首个 Cookie 的 value，解析丢失。

## 保留的正式修复

- `SessionCookieNameResolver::resolveSameSite()` + Strategy 在 Set-Cookie 时解析
- `HeaderCollector` 正确输出 Partitioned
- `WlsRequest` / `worker_http_message` / `WorkerPolicyKernel`：Cookie 重复头用 `"; "` 拼接
- ACL / Login：使用端口限定 Cookie 名

## 清理

- 已移除全部 `debug-38d697` / agent log 埋点
