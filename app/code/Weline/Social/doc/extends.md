# Weline_Social 扩展开发指南

## 扩展入口

外部模块通过 `extends/module/Weline_Social/platforms.php` 注册平台 Provider。

```php
<?php

declare(strict_types=1);

return [
    Vendor\Module\Platform\MastodonLikeProvider::class,
    Vendor\Module\Platform\RegionalForumProvider::class,
];
```

一个模块可以注册多个平台 Provider。

## Provider 要求

Provider 必须实现：

```php
Weline\Social\Interface\SocialPlatformProviderInterface
```

可选实现：

```php
Weline\Social\Interface\SocialPlatformConfigTesterInterface
```

`getDefinition()` 至少返回：

- `code`
- `title`
- `family`
- `auth_modes`
- `capabilities`
- `content_types`
- `docs`
- `icon`（可选，默认使用 `code`；用于解析模块内 SVG 图标）
- `icon_svg`（可选，必须是不包含脚本、事件属性或 `javascript:` 的受控 SVG）

`code` 必须全局唯一。

## 图标约定

Provider 不需要在模板中输出图标。`Weline\Social\Service\SocialPlatformIconService` 会按以下顺序解析：

1. Provider 定义里的受控 `icon_svg`。
2. `Weline_Social/view/statics/icons/social/{icon}.svg`（核心已内置各平台品牌 SVG，禁止用字母占位图替代已知平台）。
3. 仅未知平台才根据 `platform_code` 生成安全 SVG fallback。

不允许后台用户提交任意未清洗 SVG。

## 账户用途

账户保存时分为两个能力：

- `widget_enabled`：仅用于前台社媒账户部件展示，可不启用发布。
- `publish_enabled`：必须通过官方凭据检测后才能发布。

发布批次会跳过或标记失败未启用发布的账户，不影响其他平台目标。

## 站点默认关系

站点与社媒账户关系由 `SocialWebsiteAccountService` 管理，扩展模块不需要直接写关系表。后台或服务端应通过 `welineSocial` QueryProvider 调用：

- `saveWebsiteAccountDefaults`
- `getWebsiteDefaultAccounts`
- `resolvePublishAccounts`

关系表只保存站点、账户、平台、排序和启停状态；真实授权凭据仍归 `SocialPlatformAccount` 加密保存。发布站点资讯时可以只传 `website_id`、`website_ids` 或 `all_sites`，发布服务会按站点默认关系解析可发布账户。

## 禁止行为

- 不允许用用户密码、Cookie 或浏览器自动化模拟登录。
- 不允许绕过平台 OAuth、审核、速率限制或授权边界。
- 不允许在日志中输出 token、secret、password、authorization、api_key、cookie。
