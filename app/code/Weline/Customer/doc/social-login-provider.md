# 第三方社媒登录 Provider 开发指南

面向要接入 `Weline_Customer` 统一社媒登录壳的其他模块。模式对齐 `Weline_Payment`：`extends` 注册 + 接口实现 + **客户指南/政策页**（侧栏可导航）。**禁止**用 `Weline_Customer::frontend::account::login::providers` Hook 注册 OAuth 提供商。

## 1. 继承后必须实现的公共方法

推荐继承 `AbstractSocialLoginProvider`（已带 OAuth HTTP 与指南元数据默认实现）。接口：`SocialLoginProviderInterface`。

| 分组 | 方法 | 说明 |
|---|---|---|
| 身份 | `getCode()` | 稳定 code，对齐配置键与绑定表 |
| 展示 | `getLabel()` / `getSummary()` | 名称与指南总览摘要 |
| Logo | `getIcon()` / `getIconSvgMarkup()` / `getBrandClass()` | 前台圆标优先 SVG |
| 排序 | `getSortOrder()` | 越小越靠前 |
| 指南/政策元数据 | `getGuideTitle()` / `getPolicyTitle()` | 页面标题 |
| 指南/政策模板 | `getGuideTemplateCode()` / `getPolicyTemplateCode()` | 默认 `guide` / `policy` |
| 布局 | `getGuideLayoutType()` / `getPolicyLayoutType()` | **必须**能注入控制器正文；默认 `payment_guide`（勿用 `help`，否则只剩标题） |
| Meta/控制台粘贴路径 | `getStorefrontPrivacyPolicyPath()` / `getStorefrontTermsPath()` / `getStorefrontDataDeletionPath()` | 后台 SystemConfig adapter 可复制；默认 `guide/social-login/{code}/policy`、`terms`、`…/policy#data-deletion` |
| OAuth | `buildAuthorizationUrl()` / `exchangeAndFetchProfile()` | 授权 URL 与资料归一化 |

抽象基类已默认实现：Logo 类名、排序、摘要/标题文案、模板 code、布局类型。第三方通常只需覆盖 `getCode/getLabel/getIconSvgMarkup` + 两个 OAuth 方法，并**提供 phtml 正文**。

## 2. 最小模块结构

```text
app/code/Vendor/WeChatLogin/
├─ extends/module/Weline_Customer/SocialLoginProvider/WeChatProvider.php
├─ extends/module/Weline_SystemConfig/Config/frontend/social-login-wechat.phtml
├─ view/templates/Frontend/guide/social-login/wechat/guide.phtml
├─ view/templates/Frontend/guide/social-login/wechat/policy.phtml
├─ etc/module.php
├─ i18n/zh_Hans_CN.csv
├─ i18n/en_US.csv
└─ register.php
```

命名空间：

```php
namespace Vendor\WeChatLogin\Extends\Module\Weline_Customer\SocialLoginProvider;
```

## 3. 指南 / 政策页（对齐支付）

公开路由（侧栏导航可互跳）：

- 总览：`/guide/social-login`
- 指南：`/guide/social-login/{code}`
- 政策：`/guide/social-login/{code}/policy`

模板路径：

`{YourModule}::templates/Frontend/guide/social-login/{code}/guide.phtml`  
`{YourModule}::templates/Frontend/guide/social-login/{code}/policy.phtml`

正文写在 phtml，用 `<lang>` / `@lang()`，不要在 Provider 类里拼大段 HTML。

壳已在登录部件与个人中心绑定卡提供「指南/政策」入口，用户可随时点进去。个人中心「连接与应用 → 社媒登录」对每个已注册 Provider 展示绑定状态与**解绑**（已绑定行即使商户暂时关闭入口仍可见，便于解除）。

## 4. SystemConfig

- `customer/social_login/{code}/client_id`
- `customer/social_login/{code}/client_secret`
- `customer/social_login/{code}/enabled`
- （可选共享）`customer/social_login/http_proxy`、`customer/social_login/http_proxy_type`：服务器无法直连提供商 token/userinfo 时使用

回调白名单：`{base}/customer/account/social-login/callback`

内置 FacebookProvider OAuth scope 固定为 `email,public_profile`（授权弹窗对应「邮箱」「姓名和头像」）。商户配置指南与前台 `/guide/social-login/facebook` 须明示这两项；开发模式仅角色/测试用户可测，对普通顾客上线前须 Advanced Access / 登录审核并将应用切 Live。第三方 Provider 若请求额外权限，须在自家 guide 与 SystemConfig hint 中同样写清。

Facebook / Instagram（Meta「应用设置 → 基本」）后台配置组还会用 adapter 展示可复制：

| Meta 字段 | Provider / adapter |
|---|---|
| 应用域名 | host（`callback-as-host`，无 scheme/port） |
| 隐私政策网址 | `getStorefrontPrivacyPolicyPath()` → `/guide/social-login/{code}/policy` |
| 服务条款网址 | `getStorefrontTermsPath()` → `/terms` |
| 用户数据删除说明网址 | `getStorefrontDataDeletionPath()` → `/guide/social-login/{code}/policy#data-deletion` |

第三方若自建政策页，覆盖上述三个 path 方法，并在自家 SystemConfig 模板里用相同 `callback-path`。

## 5. 刷新

```bash
php bin/w extends:rebuild
```

## 6. 内置样板

`Weline_Customer/extends/module/Weline_Customer/SocialLoginProvider/`  
与 `view/templates/Frontend/guide/social-login/{google,facebook,instagram}/`
