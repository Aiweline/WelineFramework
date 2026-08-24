# 统一 Captcha 与 Google reCAPTCHA Enterprise

## 运行时契约

业务模块只依赖 `Weline\Captcha\Api\CaptchaManagerInterface`：

- `renderChallenge($context)`：由 `<w:form>` 的关闭前事件调用。
- `verifySubmission($submission, $intent, $hostname, $ip)`：业务写入前调用。

`<w:form>` 的 `captcha` 默认值为 `off`，普通 GET/POST 表单都不注入挑战。只有显式设置
`captcha="auto"` 或 `captcha="required"` 才会启用：`auto` 仅对 POST 注入，
`required` 声明入口必须验证。启用后，默认优先 Google Enterprise；配置不完整或未显式关闭
Google 时必须使用 `local_image`，不能出现已经选择验证却静默跳过的状态。

登录入口使用固定 intent：

- 后台登录：`admin.login`
- 前台客户登录：`customer.login`

两者在提交端调用 `verifySubmission()`，验证失败时在账号查询或密码验证之前终止登录。
Captcha 模块未启用时，登录模块保持可选依赖兼容，页面不会注入挑战，提交也不会被 Captcha
模块阻断。

Provider 实现 `VerificationProviderInterface`，并可通过
`Weline_Captcha::providers::collect` 注册。`captcha/google/enabled` 默认开启；只有
Google 已启用且配置完整时才选择 `google_enterprise`；此后远端失败必须拒绝，不能回退本地
图形验证码。未配置完整或显式关闭 Google 时始终选择 `local_image`。

本地挑战只保存 `password_hash`，成功或失败都会删除记录。Enterprise Token 保存 SHA-256
摘要防重放，并校验 `valid`、`action`、`hostname`、`createTime`、风险分数。
本地图形挑战以服务器生成的图像渲染：优先 GD + TrueType 输出 PNG；若不可用则回退为
内联 SVG 描边字形（不含答案文本节点，也不依赖可能被 CSP 禁止的外链字体）。

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
