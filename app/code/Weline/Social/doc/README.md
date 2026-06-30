# Weline_Social 融媒体管理模块

## 模块概述

`Weline_Social` 提供社媒、博客、论坛、消息渠道的统一平台账户、AI 创意和一键多平台发布能力。模块自身可以内置多个平台 Provider，外部模块也可以通过 `extends/module/Weline_Social/platforms.php` 一次追加多个 Provider。

## 核心能力

- 平台库：通过 `etc/social_platforms.php` 注册内置平台，通过 extends 注册外部平台。
- 账户连接：支持 OAuth、API Key、Webhook、Bot Token、Application Password、JWT Admin Token 等官方凭据模式。
- 账户展示：账户可独立启用 `widget_enabled`，只用于前台社媒账户部件展示。
- 发布控制：账户必须 `publish_enabled=1` 且官方凭据检测通过后才能进入发布批次。
- 站点社媒：通过 `weline_social_website_account` 维护站点与社媒账户的默认关系，发布站点资讯时先选站点并自动带出该站点默认社媒账户，也支持全部站发布。
- SVG 图标：平台定义自动按 `platform_code` 解析 SVG 图标，模板不硬编码平台图标。
- AI 创意：通过 `Weline_Ai` 的 `AiService` 生成统一稿件和平台变体。
- 批量发布：创建 batch 和 target，单个平台失败不影响其他平台。
- 队列执行：`SocialPublishQueue` 可消费单个 `SocialPublishTarget`。
- fake 模式：内置 `fake_browser` Provider，供真实浏览器 smoke 验证使用，不访问外部平台。

## QueryProvider

由于 `WeShop_Social` 已经占用 `social` provider，`Weline_Social` 使用：

```js
const Social = await Weline.Api.resource('welineSocial')
```

支持操作：

- `listPlatforms`
- `getPlatform`
- `listAccounts`
- `getAccount`
- `listWidgetAccounts`
- `startAuthorization`
- `saveCredentialAccount`
- `testAccount`
- `disableAccount`
- `listWebsites`
- `listWebsiteAccountRelations`
- `saveWebsiteAccountDefaults`
- `getWebsiteDefaultAccounts`
- `resolvePublishAccounts`
- `generateCreative`
- `createPublishBatch`
- `getPublishBatchStatus`

后台页面通过后端 worker 的 `Weline.Query.request('welineSocial', operation, params)` 调用这些操作；前台 worker 只暴露平台库和可展示账户等只读能力。

## 站点与社媒账户关系

后台 `站点社媒` 页签用于为每个站点配置默认社媒账户。关系只保存 `website_id`、`account_id`、`platform_code`、默认标记、排序和状态，不保存 token、secret、cookie 或 Authorization 等凭据。

一键发布窗口默认先选择站点，再调用 `getWebsiteDefaultAccounts` 勾选该站点可发布账户；勾选 `全部站发布` 时调用 `resolvePublishAccounts`，按所有站点默认关系取账号并集。发布服务端也会兜底解析 `website_id` / `website_ids` / `all_sites`，避免只依赖前端勾选状态。账户仍必须满足 `publish_enabled=1` 且 `test_status=passed` 才能进入发布任务。

## 社媒账户部件

模块注册 `social-accounts` 部件：

```php
// extends/module/Weline_Widget/Weline_Social/widget.php
return [
    'Weline_Social::theme/frontend/widgets/social/social-accounts/default.phtml',
];
```

部件只渲染满足以下条件的账户：

- `status=active`
- `widget_enabled=1`
- `profile_url` 为 `http` 或 `https`

可配置参数包括 `title`、`layout`、`icon_size`、`icon_style`、`show_label`、`platforms`、`limit`。没有可展示账户时不输出示例链接。

## 扩展平台

扩展模块创建：

```php
// extends/module/Weline_Social/platforms.php
return [
    Vendor\Module\Platform\CustomProvider::class,
];
```

Provider 必须实现 `Weline\Social\Interface\SocialPlatformProviderInterface`，需要凭据检测时实现 `SocialPlatformConfigTesterInterface`。

## Fake 浏览器验证

访问 `/weline_social/frontend/social/smoke?fake=1&relation=1&no_publish=1` 会执行：

1. 创建或更新 fake 账户。
2. 读取站点列表中的首个站点。
3. 保存该站点与 fake 账户的默认社媒关系。
4. 解析该站点默认可发布账户。
5. 页面显示 `data-social-site-account-smoke="passed"`，且不创建发布批次。

如不传 `no_publish=1`，页面保留旧的 fake 发布冒烟能力，只调用本地 `fake_browser` Provider，不访问外部社媒平台。

该流程只使用本地 fake Provider，不保存真实平台凭据，不访问外部社媒平台。

## 安全边界

- 不保存社媒登录密码。
- 不使用 Cookie、爬虫或浏览器模拟绕过授权。
- 凭据使用 `Weline_Ai\Service\SecretStoreService` 加密存储。
- 发布日志会脱敏 token、secret、password、authorization、api_key 等字段。
- 前台部件和 QueryProvider 安全输出不包含加密凭据。
- 没有官方开发文档或官方 API 入口的平台不接入。
