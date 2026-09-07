# Cloudflare API Token 权限配置指南

更新：2026-09-05，对应 Weline_Cdn 1.0.4。

## 账户与域名配置

在后台 **CDN管理 > 账户管理** 保存 Cloudflare 实际签发的 API Token；账户名称为本地标识，Account ID 可选。Token 通过已有 `secret_ref` 边界保存。编辑表单不回显 Token；留空保留原值，输入新值才替换。

在 **域名管理** 选择网站、适配器，填写公开主机名和 Cloudflare Zone ID，选择账户或继承默认账户。默认网站 `website_id=0` 有效。`www.example.com`、`shop.example.com` 通常使用父 Zone `example.com` 的 ID；发给 Cloudflare 的清理 URL 仍为原始完整 URL。同一 Zone 可以用于多个网站/域名记录；独立配置的子域 Zone 优先匹配。参见 [子域 Zone 设置](https://developers.cloudflare.com/dns/zone-setups/subdomain-setup/)。

企业邮箱的 OAuth/DNS 设置另见 [Cloudflare-OAuth-Client-Setup.md](Cloudflare-OAuth-Client-Setup.md)，它不等同于 CDN 缓存权限。

## 按操作配置权限

| 操作 | 权限与资源 |
|---|---|
| 清理缓存 | `Zone / Cache Purge / Purge`（API 权限 Cache Purge）；资源选目标 Zone |
| 自动查找 Zone ID | `Zone / Zone / Read`，用于 `GET /zones` |
| 管理缓存规则 | `Zone / Cache Rules / Read` 或 `Edit`，按读取/写入需要配置 |

手工配置 Zone ID 的清缓存流程不要求额外授予缓存规则编辑权。[Zone 详情 API](https://developers.cloudflare.com/api/resources/zones/methods/get/) 的允许权限包含 Cache Purge；已知 ID 的只读访问测试不要求额外开通 Zone 列表权限。Token 可在 [Cloudflare API Tokens](https://dash.cloudflare.com/profile/api-tokens) 创建，并限制到实际使用的 Zone。

## 后台连接验证

保存账户后点击“测试已保存账户连接”。可选输入 Zone ID；输入框仅用于当次测试，不修改域名配置。

1. `GET /user/tokens/verify` 必须返回 HTTP 2xx、JSON `success: true`、`result.status: active`，才确认 Token 状态有效。
2. 指定 Zone ID 时再执行 `GET /zones/{zone_id}`，核对返回 ID 和 Zone 名称；Query API 传入 `domain` 时也核对其为该 Zone 或点分隔子域。
3. 结果区分 `token_verified` 与 `zone_verified`，始终返回 `purge_verified: false`。Token verify 不返回权限/资源范围，也不应用 Token 的客户端 IP 限制；只有真实清缓存结果能验证该操作可用。

测试过程不发出 purge、DNS 或规则写请求。普通 API Token 使用上述验证端点；OAuth 连接使用独立的 OAuth 流程。

依据：[Token verify](https://developers.cloudflare.com/api/resources/user/subresources/tokens/methods/verify/)、[Token 限制](https://developers.cloudflare.com/fundamentals/api/how-to/restrict-tokens/)。

## 清理结果与批量

Cloudflare 各套餐均支持按 URL、Host、Tag 和 Prefix 清理。当前单 URL 请求批量上限：Free/Pro/Business 100，Enterprise 500；Host/Tag/Prefix 每批 100。本模块统一分成每批最多 100 项，按顺序发送；仍受账户共享限速约束，遇到服务商拒绝会返回失败，不自行宣称完成。套餐限速以 [当前清理文档](https://developers.cloudflare.com/cache/how-to/purge-cache/) 为准。

仅 HTTP 2xx 且 JSON `success` 严格为布尔 `true` 才算该批被服务商接受。批量响应保留已接受的 `purged_count`、总 `requested_count` 和 `purge_ids`；后续批次失败则整体 `success: false`。这表示 API 接受情况，实际缓存失效需观察目标 URL 的 CDN 响应。

网站与店铺/渠道资源变更按公开基址的 Host/Prefix 范围清理，`Weline_Cdn::request` 的 `purge_all` 按绑定或显式请求主机清理，避免同 Zone 其他主机被一起清除。显式管理 API/CLI 的 `mode=everything` 保留整 Zone 清理语义。按 URL 清理保留协议、主机、路径、查询参数，以及调用方提供的缓存键 Header 对象。

依据：[Purge API](https://developers.cloudflare.com/api/resources/cache/methods/purge/)、[按 URL 清理](https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-single-file/)、[按 Host 清理](https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-hostname/)。

## 验收与诊断

开发定向回归：`php app/code/Weline/Cdn/Test/Regression/CdnDeliveryRegression.script.php`，不连接外部服务。

当前宿主的事件分发直接执行 CDN 观察者，既有异步 XML 声明尚未接入实际异步投递链；此处不承诺业务保存之外的网络执行、Outbox 持久化或自动重试。没有账户/域名配置时提前返回，不能作为外部清理成功证据。

真实验收需已有有效账户、对应 Zone 和可缓存 URL：先保存并重新打开账户确认凭据保留，再做只读连接验证，最后按实际业务变更清理受影响 URL，记录响应成功状态与 purge ID，并观察缓存响应。诊断只输出账户 ID、适配器、激活状态、默认标记和 `has_credentials`；不要输出 Token、secret_ref 或完整账户模型。
