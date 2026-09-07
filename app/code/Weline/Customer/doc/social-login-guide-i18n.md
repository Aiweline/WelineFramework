# 社媒登录客户指南 i18n 与 AI 翻译

> 适用范围：`/guide/social-login` 聚合页、各提供方 **登录指南** / **登录政策** 正文。  
> 对齐支付指南约定：`doc` 见 `Weline_Payment/doc/payment-customer-guide-i18n.md`。

## 交付约定

| 交付物 | 路径 |
| --- | --- |
| Provider 元数据 | `extends/.../SocialLoginProvider/*`（`getGuideTitle()` 等用 `__()`） |
| 指南/政策正文 | `view/templates/frontend/guide/social-login/{code}/guide.phtml`、`policy.phtml`（`<lang>`） |
| 外壳 | `partials/`、`index.phtml` |
| 词典 | `Weline_Customer/i18n/zh_Hans_CN.csv`、`en_US.csv` |

路由：`/guide/social-login`、`/{code}`、`/{code}/policy`。

## 流水线

1. **创作**：phtml 只用 `<lang>` / `@lang()`，源串简体中文。  
2. **收集**：`php bin/w i18n:collect Weline_Customer`  
3. **审计**：`php bin/w customer:guide:social-login:i18n-audit [--provider=google] [--locale=en_US] [--json]`  
4. **AI 入队**：`php bin/w customer:guide:social-login:i18n-translate [--provider=google] [--locale=en_US]`  
   - domain=`social_login_guide`（I18n `AiTranslateQueue` 已放行）

服务：`SocialLoginGuideI18nCatalog` / `SocialLoginGuideI18nService` / `SocialLoginGuideTranslationQueueService`。
