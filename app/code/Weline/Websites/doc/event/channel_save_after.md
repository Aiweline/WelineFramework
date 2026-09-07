# 渠道保存后事件

## 事件名称

`Weline_Websites::channel_save_after`

## 触发时机

`ScopeManagement::postUpdateChannel` 在渠道核心字段写入成功后触发。

## 事件数据结构

```php
[
    'channel_id' => 1,
    'store_id' => 1,
    'website_id' => 1,
    'channel' => [...], // SalesChannelSummary::toArray()
    'before' => [...], // 保存前 SalesChannelSummary::toArray()
    'post_data' => [...], // 原始 POST，含 extensions
    'action' => 'edit',
]
```

## Extension payload convention

扩展模块字段写在 `extensions[{module_code}]` 下；`Weline_Websites` 不解析业务扩展字段。

Channel 当前没有独立 URL 字段，公开入口继承所属 Store，再继承 Website。消费者通过 `Weline\Websites\Api\Catalog\StoreCatalogInterface::byId(store_id)` 读取 `StoreSummary->url`；`before.store_id` 保留原所属店铺，网站/店铺/渠道 ID 为 `0` 仍有效。
