# 店铺保存后事件

## 事件名称

`Weline_Websites::store_save_after`

## 触发时机

`ScopeManagement::postUpdateStore` 在店铺核心字段写入成功后触发。

## 事件数据结构

```php
[
    'store_id' => 1,
    'website_id' => 1,
    'store' => [...], // StoreSummary::toArray()
    'before' => [...], // 保存前 StoreSummary::toArray()，含旧 url（可为 null）
    'post_data' => [...], // 原始 POST，含 extensions
    'action' => 'edit',
]
```

## Extension payload convention

扩展模块字段写在 `extensions[{module_code}]` 下；`Weline_Websites` 不解析业务扩展字段。

SEO/CDN 使用 `store.url` 与 `before.url` 对比当前、旧的公开入口；空 URL 继承所属 Website 的 URL。跨模块读取使用 `Weline\Websites\Api\Catalog\StoreCatalogInterface::byId()` 与 `WebsiteCatalogInterface::all()`，网站/店铺 ID 为 `0` 同样有效。
