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

## MVP ChangedType

`product_search_projection`、`theme`、`theme_layout`、`cms_page`、`url_rewrite`。

权威：`doc/开发/team/controller-extra-fpc/总逻辑.md`
