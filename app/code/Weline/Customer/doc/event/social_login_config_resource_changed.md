# 社媒登录 SystemConfig 与 `resource_changed`

跨模块资源变更信封走 Framework：`w_changed()` → `Weline_Framework::resource_changed`。

## 触发

- Producer：`SystemConfig::saveScopeConfig` → `SystemConfigResourceChangePublisher`
- 资源类型：`system_config`
- 过滤：`after.module === Weline_Customer` 且 `changed_fields` 含 `customer/social_login/*`

## Impact（Publisher）

| 字段 | 值 |
|---|---|
| `namespaces` | 既有 `global/storefront/config` + **`global/storefront/auth`**（及 website 作用域） |
| `urls` | `/customer/account/login`、`/customer/account/register` |

店面 FPC 向量已纳入 `storefront/auth`（`StorefrontCacheKeyContextResolver`）。

## Customer 消费者

- Observer：`Weline\Customer\Observer\SocialLoginConfigResourceChanged`
- 本地清理：`SocialLoginStorefrontCacheInvalidator`（进程 FPC、`fpc`/`router` 池、`Template::clearStaticHookCaches`、WLS 广播）
- **禁止**在 Observer 内直接 purge CDN；CDN/SEO 由 Framework 接收方处理

## 验收要点

后台切换「激活前台入口」/ `quick_prompt` 后，未登录访问登录页应看到最新 Logo / One Tap 引导，不得继续命中旧 FPC/hook 输出。
