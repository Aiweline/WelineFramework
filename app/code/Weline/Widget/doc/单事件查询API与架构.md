# Widget 查询器 API 与架构

Widget 模块通过 extends 注册查询器（`WidgetQueryProvider`），实现 `QueryProviderInterface`，对外提供部件列表、配置定义、配置表单、预览等查询能力。

其它模块通过 `w_query('widget', ...)` 或 `dispatch('Weline_Widget::query', ...)` 调用。

---

## 1. 设计原则

- **Widget 为主**：查询器实现、支持的 operation、描述均属于 Widget 模块。
- **单一对外入口**：通过 `provider=widget` + `operation` + `params` 选择能力。
- **接口约束**：实现 `QueryProviderInterface`，含 `getProviderName()`、`execute()`、`getDescriptor()`。
- **使用说明可查**：通过 `w_query('framework', 'introspect', { what: 'operations', provider: 'widget' })` 查询所有支持的 operation 及参数。

---

## 2. 注册方式

查询器位于：`extends/module/Weline_Framework/Query/WidgetQueryProvider.php`

框架通过 `ExtendsData::getExtendedBy('Weline_Framework')` 自动扫描并注册。

---

## 3. 支持的 operation

| operation | 说明 | params | result |
|-----------|------|--------|--------|
| `getAvailableList` | 可用部件列表（分组、过滤、i18n） | `page_type`, `filter_options` | 分组后的部件数组 |
| `getParamDefinitions` | 某部件的参数定义（schema） | `widget_module`, `widget_code`, `area` | 参数定义数组 |
| `getConfigForm` | 配置表单 HTML | `layout_id`, `params`, `config` | 表单 HTML 字符串 |
| `renderField` | 单字段 HTML | `key`, `param`, `value`, `layout_id`, `attrs` | 字段 HTML 字符串 |
| `validateConfig` | 校验配置值 | `params`, `values` | `{valid, errors}` |
| `processConfig` | 保存前处理配置 | `params`, `values` | 处理后的配置数组 |
| `preview` | 部件预览 HTML | `widget_module`, `widget_code`, `config`, `area` | 预览 HTML 字符串 |
| `getRegisteredTypes` | 已注册的 ParamType 类型名 | 无 | 字符串数组 |

---

## 4. 调用示例

### 4.1 前端 (JS)

```js
const list = await window.w_query('widget', 'getAvailableList', {
    page_type: 'homepage',
    filter_options: { slot_id: 'widget-hero' }
});
```

### 4.2 后端 (PHP)

```php
$result = $queryService->execute('widget', 'getAvailableList', [
    'page_type' => 'homepage',
]);
```

### 4.3 通过模块事件

```php
$eventData = [
    'data' => [
        'operation' => 'getAvailableList',
        'params' => ['page_type' => 'homepage'],
    ],
];
$this->getEventsManager()->dispatch('Weline_Widget::query', $eventData);
$list = $eventData['data']['result'] ?? [];
```

### 4.4 查询使用说明

```js
const ops = await window.w_query('framework', 'introspect', {
    what: 'operations',
    provider: 'widget'
});
```

---

## 5. 架构

```
调用方
  -> Query API (Backend/Frontend)
    -> FrameworkQueryService
      -> QueryProviderRegistry
        -> WidgetQueryProvider (extends 注册)
          -> WidgetListService / WidgetConfigService / WidgetPreviewService
```

Widget 内部 Service 保持不变，仅由 `WidgetQueryProvider` 调用。
