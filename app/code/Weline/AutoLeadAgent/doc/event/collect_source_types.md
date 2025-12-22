# 自动寻客来源类型收集事件

## 事件名称

`Weline_AutoLeadAgent::lead_search_task::collect_source_types`

## 触发时机

- 后台创建自动寻客任务弹窗打开时，前端通过 AJAX 调用后台接口，
  后台在返回可选类型列表前触发本事件。

## 事件目的

- 让不同业务模块以**解耦**的方式向自动寻客模块注册自己的"寻客来源类型"和"配置项"。
- 例如：
  - 店铺模块提供 `store` 类型及所有可选店铺；
  - 其他模块可以提供 `product`、`article` 等类型及其选项。

## 事件数据结构

事件以数组形式传参：

```php
[
    'source_types' => [
        [
            'type'          => 'store',   // 类型标识（必填，字符串）
            'name'          => '店铺',    // 类型名称（必填，用于前端展示）
            'handler_class' => 'WeShop\\Store\\Service\\LeadSearchHandler', // 处理类（可选）
            'options'       => [         // 该类型下的可选条目（可选）
                [
                    'id'          => 1,              // 条目ID（必填，整数）
                    'name'        => '示例店铺',     // 条目名称（必填）
                    'description' => '用于说明的文案', // 条目描述（可选）
                    'meta'        => [              // 其它扩展字段（可选）
                        'code'       => 'demo',
                        'website_id' => 1,
                    ],
                ],
                // ...
            ],
        ],
        // 其他类型 ...
    ],
]
```

## 观察者实现规范

### 1. 观察者类

- 必须实现 `Weline\Framework\Event\ObserverInterface`。
- 从事件中读取并更新 `source_types`：

```php
public function execute(\Weline\Framework\Event\Event $event): void
{
    $data = $event->getData();
    $sourceTypes = $data['source_types'] ?? [];
    if (!is_array($sourceTypes)) {
        $sourceTypes = [];
    }

    // 构造当前模块的类型定义
    $sourceTypes[] = [
        'type'          => 'store',
        'name'          => (string)__('店铺'),
        'handler_class' => \WeShop\Store\Service\LeadSearchHandler::class,
        'options'       => $options, // 模块自己生成
    ];

    $event->setData('source_types', $sourceTypes);
}
```

### 2. 事件配置

在模块 `etc/event.xml` 中注册观察者，例如：

```xml
<event name="Weline_AutoLeadAgent::lead_search_task::collect_source_types">
    <observer name="WeShop_Store::lead_search_source_type_collector"
              instance="WeShop\Store\Observer\LeadSearchSourceTypeCollector"
              disabled="false"
              shared="true"
              sort="100"/>
</event>
```

## 返回约定

- 如果没有任何模块提供 `source_types`，事件结束后数组可以为空，前端应做降级处理（提示"暂无可用类型"）。
- 建议每个类型至少提供：
  - `type`：唯一字符串标识；
  - `name`：用户可理解的名称；
  - `options`：若能立即给出配置项则前端可直接展示下拉。

## 注意事项

- 不建议在观察者中执行耗时操作（如真正的寻客逻辑），本事件只负责**收集配置**。
- 实际寻客任务执行应在任务创建后，由队列 / Cron / 专用服务完成。

