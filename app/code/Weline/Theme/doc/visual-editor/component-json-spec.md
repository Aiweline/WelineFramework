# component.json 规范

> 版本: 2.1.0

## 概述

`component.json` 定义了模板的组件库配置，包括区域定义、组件定义、slots 配置等。

## 文件位置

```
app/code/GuoLaiRen/PageBuilder/view/templates/style/{template}/components/component.json
```

## 完整结构

```json
{
    "$schema": "2.1.0",
    "template": "tpmst",
    "name": "模板名称",
    "description": "模板描述",
    "version": "2.1.0",
    "regions": { ... },
    "components": { ... },
    "nav_config": { ... },
    "config_inheritance": { ... }
}
```

## 区域定义 (regions)

```json
{
    "regions": {
        "header": {
            "name": "头部区域",
            "name_en": "Header",
            "description": "页面顶部区域",
            "multiple": false,
            "required": true,
            "accepts": ["header"],
            "default_component": "header-nav",
            "supports_slot_nesting": true
        },
        "content": {
            "name": "内容区域",
            "name_en": "Content",
            "description": "页面主要内容区域",
            "multiple": true,
            "required": false,
            "accepts": ["content", "widget"],
            "default_components": ["slider", "advantages"],
            "supports_slot_nesting": true
        },
        "footer": {
            "name": "底部区域",
            "name_en": "Footer",
            "description": "页面底部区域",
            "multiple": false,
            "required": true,
            "accepts": ["footer"],
            "default_component": "footer-links",
            "supports_slot_nesting": true
        }
    }
}
```

### 区域属性

| 属性 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `name` | string | 是 | 区域显示名称 |
| `name_en` | string | 否 | 英文名称 |
| `description` | string | 否 | 区域描述 |
| `multiple` | boolean | 是 | 是否支持多个组件 |
| `required` | boolean | 是 | 是否必须有组件 |
| `accepts` | array | 是 | 接受的组件类别 |
| `default_component` | string | 否 | 默认单个组件 |
| `default_components` | array | 否 | 默认多个组件 |
| `supports_slot_nesting` | boolean | 否 | 是否支持 slot 嵌套 |

## 组件定义 (components)

```json
{
    "components": {
        "header-nav": {
            "name": "导航头部",
            "name_en": "Navigation Header",
            "description": "Logo居中布局，包含导航菜单",
            "region": "header",
            "category": "header",
            "type": "section",
            "thumbnail": "asset/img/logo-icon-128.avif",
            "icon": "bi-layout-navbar",
            "sort_order": 1,
            "is_default": true,
            "compatible_styles": ["*"],
            "placeable_in": ["header"],
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
            },
            "compatible_slot_types": ["*"],
            "config_groups": ["header", "navigation"],
            "config_schema": { ... },
            "file": "header/nav.phtml"
        }
    }
}
```

### 组件属性

| 属性 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `name` | string | 是 | 组件显示名称 |
| `name_en` | string | 否 | 英文名称 |
| `description` | string | 否 | 组件描述 |
| `region` | string | 是 | 所属区域 |
| `category` | string | 是 | 组件类别 |
| `type` | string | 是 | 组件类型 (section/widget/layout) |
| `thumbnail` | string | 否 | 缩略图路径 |
| `icon` | string | 否 | 图标类名 |
| `sort_order` | int | 否 | 排序权重 |
| `is_default` | boolean | 否 | 是否默认组件 |
| `compatible_styles` | array | 否 | 兼容的模板列表 |
| **`placeable_in`** | array | **是** | 可放置的区域列表 |
| **`slots`** | object | 否 | Slot 定义 |
| **`compatible_slot_types`** | array | 否 | 兼容的 slot 类型 |
| `config_groups` | array | 否 | 配置组 |
| `config_schema` | object | 否 | 配置项定义 |
| `file` | string | 是 | 模板文件路径 |

## Slot 定义

```json
{
    "slots": {
        "slot_name": {
            "name": "Slot 显示名称",
            "accepts": ["content", "widget"],
            "slot_type": "filters-group",
            "max": 20
        }
    }
}
```

### Slot 属性

| 属性 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `name` | string | 是 | Slot 显示名称 |
| `accepts` | array | 是 | 接受的组件类别 |
| `slot_type` | string | 否 | Slot 类型标识 |
| `max` | int | 否 | 最大组件数量 |

## 完整示例

```json
{
    "$schema": "2.1.0",
    "template": "tpmst",
    "name": "Teen Patti Master",
    "description": "游戏落地页模板",
    "version": "2.1.0",
    "regions": {
        "header": {
            "name": "头部区域",
            "accepts": ["header"],
            "multiple": false,
            "required": true,
            "supports_slot_nesting": true
        },
        "content": {
            "name": "内容区域",
            "accepts": ["content", "widget"],
            "multiple": true,
            "required": false,
            "supports_slot_nesting": true
        },
        "footer": {
            "name": "底部区域",
            "accepts": ["footer"],
            "multiple": false,
            "required": true,
            "supports_slot_nesting": true
        }
    },
    "components": {
        "faq": {
            "name": "常见问题",
            "region": "content",
            "category": "content",
            "type": "section",
            "placeable_in": ["content"],
            "slots": {
                "items": {
                    "name": "FAQ 项目",
                    "accepts": ["content", "widget"],
                    "slot_type": "faq-item",
                    "max": 30
                }
            },
            "file": "content/faq.phtml"
        },
        "faq-item-widget": {
            "name": "FAQ 项目小部件",
            "region": "content",
            "category": "widget",
            "type": "widget",
            "placeable_in": ["content"],
            "compatible_slot_types": ["faq-item"],
            "file": "widget/faq-item.phtml"
        }
    }
}
```

## 验证

使用 `ComponentValidator` 验证 component.json：

```php
$validator = ObjectManager::getInstance(ComponentValidator::class);
$result = $validator->validateTemplate('tpmst');

if (!$result['valid']) {
    foreach ($result['errors'] as $error) {
        echo "Error: {$error}\n";
    }
}
```

## 迁移指南

从 2.0.0 升级到 2.1.0：

1. 添加 `$schema` 字段
2. 为每个组件添加 `placeable_in` 字段
3. 为容器组件添加 `slots` 字段
4. 为可嵌套组件添加 `compatible_slot_types` 字段
5. 为区域添加 `supports_slot_nesting` 字段
