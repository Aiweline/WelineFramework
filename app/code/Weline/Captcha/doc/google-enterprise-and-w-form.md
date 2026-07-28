# 统一 Captcha 与 Google reCAPTCHA Enterprise

## 运行时契约

业务模块只依赖 `Weline\Captcha\Api\CaptchaManagerInterface`：

- `renderChallenge($context)`：由 `<w:form>` 的关闭前事件调用。
- `verifySubmission($submission, $intent, $hostname, $ip)`：业务写入前调用。

`<w:form>` 的 `captcha` 默认值为 `auto`。POST 表单由关闭前事件自动注入当前 Provider
挑战，GET 表单不注入。统一 Captcha 没有总关闭开关：Google Enterprise 未启用或配置不完整
时必须使用 `local_image`，不能出现无验证码状态。显式 `off` 只保留给经过评审的框架内部豁免，
官方业务 POST 表单不得使用。

Provider 实现 `VerificationProviderInterface`，并可通过
`Weline_Captcha::providers::collect` 注册。只有 Google 已启用且配置完整时才选择
`google_enterprise`；此后远端失败必须拒绝，不能回退本地图形验证码。其余状态始终选择
`local_image`。

本地挑战只保存 `password_hash`，成功或失败都会删除记录。Enterprise Token 保存 SHA-256
摘要防重放，并校验 `valid`、`action`、`hostname`、`createTime`、风险分数。
本地图形挑战以服务器生成的内联 SVG 渲染，SVG 仅包含匿名图形段，不含答案文本，也不依赖
可能被 CSP 禁止的 `data:` 图片地址。

## Google 一键授权

SystemConfig：`Weline_Captcha / backend`。流程为：

1. 配置全局 OAuth Client ID / Secret。
2. 点击“授权 Google”，使用 `state + PKCE + cloud-platform`。
3. 从授权账户可访问的 Project 中选择一个。
4. 系统收集 Websites 域名，调用 Enterprise Keys API 创建并绑定 Key。
5. 执行连接测试；需要时撤销授权。

OAuth Client Secret、Access Token、Refresh Token 以敏感配置保存，不写日志。Access Token 401
时允许使用 Refresh Token 重试一次。执行账户需要相应 Project 的
`recaptchaenterprise.keys.create` 权限。

## 配置与兼容

- Google Project、Site Key、允许域名、阈值和启用状态支持 Website Scope。
- OAuth Client 与 Token 作为系统授权凭据保存在 Global Scope。
- 旧 v2/v3 配置迁入 `captcha/legacy/*`；迁移器只填写空键。
- 旧后台 `/captcha/backend/config` 保留一版并重定向统一 SystemConfig。
