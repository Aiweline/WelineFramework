# Weline_Customer 扩展点

本模块通过 `extends.php` 声明扩展点。其他模块用约定目录投放实现类，**不要**用 Hook 注册社媒 OAuth 提供商。

## SocialLoginProvider

| 项 | 值 |
|---|---|
| 路径 | `extends/module/Weline_Customer/SocialLoginProvider/` |
| 接口 | `Weline\Customer\Interface\SocialLoginProviderInterface` |
| 抽象基类（可选） | `Weline\Customer\Service\SocialLogin\AbstractSocialLoginProvider` |
| 多实现 | 是 |

必须覆盖：身份 code、展示（含 Logo SVG）、OAuth 两方法；以及客户指南/政策元数据（抽象类有默认值）并提供：

`view/templates/Frontend/guide/social-login/{code}/guide.phtml`  
`view/templates/Frontend/guide/social-login/{code}/policy.phtml`

公开导航：`/guide/social-login`、`/guide/social-login/{code}`、`/guide/social-login/{code}/policy`。

接入指南见 [social-login-provider.md](social-login-provider.md)。

## AccountMenuSignalProvider

| 项 | 值 |
|---|---|
| 路径 | `extends/module/Weline_Customer/AccountMenuSignalProvider/` |
| 接口 | `Weline\Customer\Api\AccountMenuSignalProviderInterface` |
| 多实现 | 是 |

- `code()`：稳定菜单编码（如 `product.quotes`），与账户 section / `data-account-menu-signal` 对齐。
- `count(customerId, websiteId)`：未读/待办条数；**禁止 SSR**，由 `account.menuSignals` + 前端 `paintAccountMenuSignals` 绘制。
- 示例：`Weline_Product` → `ProductQuoteMenuSignalProvider`。
