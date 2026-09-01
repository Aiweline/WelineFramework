# 活动主题保存与 `w_changed` / `resource_changed`

领域事件名：`Weline_Promotion::theme_save_after`（模块事件登记）。  
跨模块资源变更信封仍走 Framework：`w_changed()` → `Weline_Framework::resource_changed`。

## 硬规则（MCP / AI 必读）

提到「changed / 资源变更 / 保存后立即生效 / 清缓存 / CDN / SEO」时，**必须**走 Framework 标准入口：

1. 在 **Framework 默认主库受管写事务**内完成业务写入。
2. `ResourceRevisionService::next('promotion_activity_theme', $themeId)`。
3. `ResourceChangeFactory::create(...)` 组装 **ResourceChange v1**（含 `impact.namespaces` / `impact.urls`）。
4. 调用 **`w_changed($change)`**（只接受 `ResourceChange` DTO，禁止传 Model / 任意数组）。
5. **禁止**在控制器里手写 CDN purge / SEO 推送；CDN、SEO 由 `Weline_Framework::resource_changed` 的接收方处理。
6. **本地前台 / FPC**：保存方在 `afterCommit` 调用 `PromotionStorefrontCacheInvalidator`（进程 FPC、`fpc`/`router` 池、WLS 共享态、payload 文件），保证 `/promotion`、`/promotion/{slug}` 立即可见。

权威契约：`app/code/Weline/Framework/doc/event/framework/resource_changed.md`。

## Producer

- 资源类型：`promotion_activity_theme`
- 类：`PromotionActivityThemeResourceChangePublisher`
- 触发点：`PromotionActivityThemeService::saveTheme()`（后台主题保存）
- `impact.urls`：`/promotion` + `/promotion/{page_slug}`；slug 变更时旧 URL 进入 `previous_urls`
- `impact.namespaces`：`website/{code}/promotion` 与 `website/{code}/promotion/{themeId}`（供 `cache_namespace` sync bump）

## 本地清理 vs 事件接收方

| 动作 | 谁做 |
|---|---|
| `w_changed` + namespace bump | 保存事务内 Producer + Framework `cache_namespace` |
| 进程 FPC / router-fpc 池 / WLS 广播 | `PromotionStorefrontCacheInvalidator`（afterCommit） |
| CDN purge / warmup | Cdn `resource_changed` Observer（async） |
| SEO 登记 | Seo `resource_changed` Observer（sync critical，按类型过滤） |

## 最小用法摘要

```php
$revision = $revisions->next('promotion_activity_theme', $themeId);
$change = $factory->create(
    resourceType: 'promotion_activity_theme',
    resourceId: $themeId,
    action: 'upsert',
    revision: $revision,
    websiteId: $websiteId,
    websiteCode: $websiteCode,
    before: $before,
    after: $after,
    changedFields: $changedFields,
    impact: [
        'namespaces' => [$nsPromotion, $nsTheme],
        'urls' => ['/promotion', '/promotion/deals'],
        'previous_urls' => [],
    ],
    origin: ['entry' => 'promotion.theme.save'],
);
w_changed($change);
// afterCommit: PromotionStorefrontCacheInvalidator::clearForTheme(...)
```
