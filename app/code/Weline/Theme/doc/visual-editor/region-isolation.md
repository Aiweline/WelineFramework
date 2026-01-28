# 区域隔离规则

## 概述

区域隔离确保组件只能放置在其指定的区域中，防止布局混乱。

## 规则定义

### 1. 基础规则

每个组件必须声明其可放置的区域（`placeable_in`）：

```json
{
  "header-nav": {
    "category": "header",
    "placeable_in": ["header"]
  }
}
```

### 2. 区域接受规则

| 区域 | 接受的类别 |
|------|-----------|
| `header` | `header` |
| `content` | `content`, `widget` |
| `footer` | `footer` |

### 3. 验证逻辑

```php
// SlotValidator::canPlace()
if (!in_array($targetRegion, $placeableIn)) {
    return ValidationResult::fail(
        sprintf('组件 [%s] 只能放置在: %s，不能放到 %s 区域',
            $componentCode,
            implode(', ', $placeableIn),
            $targetRegion
        ),
        'REGION_NOT_ALLOWED'
    );
}
```

## 错误处理

### 常见错误码

| 错误码 | 说明 | 解决方案 |
|--------|------|----------|
| `REGION_NOT_ALLOWED` | 组件不能放入目标区域 | 检查组件的 `placeable_in` 配置 |
| `COMPONENT_NOT_FOUND` | 组件未注册 | 扫描组件或检查 component.json |

## 前端提示

当用户尝试将组件拖拽到不兼容的区域时：

1. 拖拽时显示红色边框
2. 放置时弹出错误提示
3. 自动滚动到兼容的组件

## 配置示例

```json
{
  "components": {
    "header-nav": {
      "category": "header",
      "placeable_in": ["header"],
      "file": "header/nav.phtml"
    },
    "slider": {
      "category": "content",
      "placeable_in": ["content"],
      "file": "content/slider.phtml"
    },
    "footer-links": {
      "category": "footer",
      "placeable_in": ["footer"],
      "file": "footer/links.phtml"
    }
  }
}
```
