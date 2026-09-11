# 统一 Captcha：多供应商与国家路由

## 运行时契约

业务模块只依赖 `Weline\Captcha\Api\CaptchaManagerInterface`：

- `renderChallenge($context)`：由 `<w:form>` 的关闭前事件 / lazy 挑战接口调用。
- `verifySubmission($submission, $intent, $hostname, $ip)`：业务写入前调用。

`<w:form>` 的 `captcha` 默认值为 `off`。只有显式设置 `captcha="auto"`、
`captcha="required"` 或 `captcha="lazy"` 才会启用；注入区域一律为 lazy 占位，由
`view/statics/js/captcha-lazy.js` 拉取 `weline_captcha/frontend/challenge`
（private/no-store）。禁止把一次性图码/token SSR 进共享 HTML。

登录入口固定 intent：

- 后台登录：`admin.login`
- 前台客户登录：`customer.login`

## 供应商

| code | 说明 |
|------|------|
| `tencent_captcha` | 腾讯云验证码（国内默认） |
| `google_enterprise` | Google reCAPTCHA Enterprise（海外默认） |
| `local_image` | 一次性本地图形挑战（最终兜底） |

Provider 实现 `VerificationProviderInterface`，经 `CaptchaProviderRegistry` 注册，
可通过 `Weline_Captcha::providers::collect` 扩展。

## 国家路由

`CaptchaProviderRouter` 选型顺序：

1. 解析访客国家码（`ClientGeoResolver`：可信头 `CF-IPCountry` /
   `CloudFront-Viewer-Country` / `X-Country-Code` / `X-Geo-Country`；无头 → `XX`）
2. `country_overrides[CC]` 命中 → 该供应商
3. 否则 `CC ∈ china_codes`（默认 `CN`）→ `china_default`（默认 `tencent_captcha`）
4. 否则 `CC === XX` → `unknown_default`（默认 `google_enterprise`）
5. 否则 → `world_default`（默认 `google_enterprise`）
6. **就绪门禁**：候选未启用或凭据不完整 → 按 `fallback_chain` 找下一个已就绪项；
   最终必为 `local_image`

后台配置（SystemConfig / Website Scope）：`captcha/routing/*`、`captcha/tencent/*`、
既有 `captcha/google/*`。

国家覆盖示例：

```text
HK=google_enterprise
MO=tencent_captcha
US=local_image
```

本阶段**不内置 IP 地理库**；生产环境应由 CDN/反代注入国家头。

## 校验与降级

- 挑战与校验均按当次请求头重算 preferred 供应商。
- 接受：`captcha_provider === preferred`，或开启 `allow_local_degrade` 且提交
  `local_image` 且本地凭证有效。
- Google / 腾讯：**风控失败仍拒绝**，不因低分/假票回退本地。
- Google Enterprise 服务端 `assessments` 出站经 `CaptchaOutboundProxy`：
  `captcha/http/proxy` → 已配置的 `customer/social_login/http_proxy` → `HTTPS_PROXY` 等。
  PHP curl 不会自动读环境代理，须显式 `CURLOPT_PROXY`（本机打洞依赖此项）。
- 客户端 SDK 加载/执行失败：派发 `weline:captcha:degrade`；lazy 运行时以
  `prefer=local_image` 重新拉挑战（仅配置允许时服务端才兑现）。
  自定义挑战槽（如客服绑定邮箱）须自行监听该事件并重拉本地图码。
- 腾讯 `trerror_` 容灾票视为未通过。
- 挑战→提交之间换 VPN 导致国家变化可能校验失败（重新拉挑战即可）。

## 腾讯云配置

1. [腾讯云验证码控制台](https://console.cloud.tencent.com/captcha) 创建应用。
2. 复制 CaptchaAppId、AppSecretKey 填入 SystemConfig「腾讯云验证码」组。
3. 服务端 `DescribeCaptchaResult`（CaptchaType=9）校验 ticket + randstr + UserIp。

## Google 配置（手填）

本模块**不提供** Google 一键授权。SystemConfig：`Weline_Captcha / backend`。

1. [reCAPTCHA Enterprise 控制台](https://console.cloud.google.com/security/recaptcha) 创建 Website Key。
2. [API 凭据](https://console.cloud.google.com/apis/credentials) 创建 API Key。
3. 填入 Project ID / Site Key / API Key。

运行时仍可用既有 OAuth Access/Refresh Token 键（若历史数据里已有）。

## 本地图形挑战

六位混合字符、有效期 5 分钟，成功/失败均一次性消费。仅保存 `password_hash`。
Enterprise / 腾讯 ticket 保存 SHA-256 摘要防重放。

## 兼容

- 旧 `CaptchaProviderInterface` / `CaptchaService` 仍可用于本地图码路径。
- 不再提供旧 Google reCAPTCHA v2/v3。
- 后台 Google OAuth 动作路由前缀仍为 `weline_captcha/backend/google/*`（历史保留）。
