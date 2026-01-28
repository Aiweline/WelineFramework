# Slot 嵌套规则

## 概述

Slot 系统允许组件定义可以嵌入子组件的位置，支持组件嵌套。

## Slot 定义

在 `component.json` 中为组件定义 slots：

```json
{
  "header-nav": {
    "slots": {
      "logo": {
        "name": "Logo 区域",
        "accepts": ["header"],
        "slot_type": "header-logo",
        "max": 1
      },
      "nav-links": {
        "name": "导航链接",
        "accepts": ["header"],
        "slot_type": "header-nav-link",
        "max": 10
      }
    }
  }
}
```

## Slot 属性

| 属性 | 类型 | 说明 |
|------|------|------|
| `name` | string | Slot 显示名称 |
| `accepts` | array | 接受的组件类别 |
| `slot_type` | string | Slot 类型标识（用于精确匹配） |
| `max` | int | 最大组件数量 |

## 验证规则

### 1. 类别匹配

Slot 的 `accepts` 必须是父组件所在区域接受的类别的子集：

```
header 区域的组件的 slot.accepts 只能包含 ["header"]
content 区域的组件的 slot.accepts 只能包含 ["content", "widget"]
footer 区域的组件的 slot.accepts 只能包含 ["footer"]
```

### 2. Slot 类型匹配

组件可以声明 `compatible_slot_types` 来限制可放入的 slot：

```json
{
  "widget-filter-group": {
    "category": "widget",
    "compatible_slot_types": ["filters-group"]
  }
}
```

### 3. 数量限制

如果 slot 配置了 `max`，超出数量时将拒绝放置。

## 嵌套组件跟随移动

当父组件移动时，其 slot 中的子组件必须跟随移动：

### 数据结构

```json
{
  "code": "faq",
  "instance_id": "comp-abc123",
  "children": {
    "items": [
      {
        "code": "faq-item-widget",
        "instance_id": "comp-xyz789",
        "config": {}
      }
    ]
  }
}
```

### 移动逻辑

```php
// LayoutService::moveComponent()
$componentWithChildren = $this->getComponentWithChildren($instanceId);
// children 会随着父组件一起移动到新位置
```

## API 验证

```php
// POST /backend/visual/api/component/validate
{
    "component_code": "faq-item-widget",
    "parent_component_code": "faq",
    "slot": "items",
    "style_code": "tpmst"
}

// 响应
{
    "success": true,
    "valid": true
}
```

## 错误码

| 错误码 | 说明 |
|--------|------|
| `NO_SLOTS_DEFINED` | 父组件未定义 slots |
| `SLOT_NOT_FOUND` | 指定的 slot 不存在 |
| `SLOT_CATEGORY_MISMATCH` | 组件类别不被 slot 接受 |
| `SLOT_TYPE_MISMATCH` | 组件不兼容 slot 类型 |
| `SLOT_MAX_REACHED` | slot 数量已达上限 |
| `SLOT_CONFIG_INVALID` | slot 配置不合法 |
