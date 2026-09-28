# Extra / FPC / Changed 薄页

## 读路径

控制器声明：

```php
/**
 * @Extra type=fpc enabled=true ttl=600 namespaces=website/default/catalog public_path_patterns=/catalog/product/*
 */
```

升级收集 → `generated/framework/controller_extra.php` → `ExtraPolicyResolver` 合并进 Storefront ns 指纹。

## 写路径

业务 Service 写成功同事务：

```php
w_changed($change); // 事实信封；不清 FPC
```

框架：ChangedType Enricher → Recipe → Capability（sync bump / afterCommit purge）。

Seo 等仍听 `resource_changed` 事件。

## 硬切

已删除：`CacheNamespaceObserver`、`CacheImpactObserver`、`Cdn\Observer\ResourceChanged`、`Theme\Observer\ResourceChanged`。

## 与 `@Cdn` / CDN 开发模式

- **`@Cdn`**：CDN **边缘**规则，**不是**源站 FPC 开关。见 `Weline_Cdn/doc/CDN注释使用指南.md` §0。  
- **CDN Scope 开发模式**：临时 bypass FPC（`FpcBypassRuleProvider`），**不**改本页 Extra 声明。规格：`Weline_Cdn/doc/开发/spec/cdn-route-realtime-fpc-devmode.md`。

## MVP ChangedType

`product_search_projection`、`theme`、`theme_layout`、`cms_page`、`url_rewrite`。

权威：`doc/开发/team/controller-extra-fpc/总逻辑.md`
