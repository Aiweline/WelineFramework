# Weline_Meta 模块扩展文档

## 概述

Weline_Meta 模块提供了一个元数据规约系统，允许其他模块通过创建 `@meta.json` 文件来定义和管理元数据结构。这个系统可以扫描、存储和管理各种类型的元数据，如布局、组件、API 等。

## 快速开始

### 1. 创建 Meta 规约文件

在您的模块中创建以下目录结构：

```
app/code/YourModule/
└── extends/
    └── Weline_Meta/
        └── YourModule/
            └── @meta.json
```

### 2. 编写 @meta.json 文件

```json
{
    "meta": {
        "base_path": "YourModule::view/templates",
        "namespace": {
            "layouts": {
                "name": "布局",
                "description": "页面布局模板",
                "default": "default",
                "type": "layout",
                "path": "layouts"
            },
            "components": {
                "name": "组件",
                "description": "页面组件模板",
                "default": "button",
                "type": "component",
                "path": "components"
            }
        }
    }
}
```

### 3. 扫描元数据

运行以下命令扫描并存储元数据到数据库：

```bash
php bin/w meta:collect
```

系统升级后（`setup:upgrade`）会自动执行扫描。

## 详细说明

### MetaConvention 扩展点

**路径**: `extends/Weline_Meta/{ModuleName}/@meta.json`

**类型**: 文件规约（JSON格式）

**用途**: 定义元数据的层级结构、默认值和选项列表。其他模块可以通过创建 `@meta.json` 文件来定义自己的元数据结构。

**要求**:
- 文件必须为有效的 JSON 格式
- 必须定义 `meta.base_path` 作为基础扫描路径
- 命名空间定义是可选的，但建议至少定义一个
- 允许多个实现（每个模块可以有多个命名空间）

### @meta.json 文件格式

```json
{
    "meta": {
        "base_path": "ModuleName::相对路径",
        "namespace": {
            "命名空间1": {
                "name": "显示名称",
                "description": "描述",
                "default": "默认值",
                "type": "类型",
                "path": "相对路径",
                "options": [
                    {
                        "value": "选项值",
                        "label": "选项标签",
                        "description": "选项描述"
                    }
                ]
            },
            "命名空间2": {
                // ...
            }
        }
    }
}
```

#### base_path

基础扫描路径，格式为 `ModuleName::相对路径`，例如：
- `Weline_Theme::view/templates`
- `YourModule::design/frontend`

#### namespace

命名空间定义，可以定义多个命名空间，每个命名空间包含：

- **name**: 显示名称（必需）
- **description**: 描述信息
- **default**: 默认值
- **type**: 类型（layout, component, partial, api 等）
- **path**: 相对于 base_path 的路径
- **options**: 选项列表（可选）

### 在模板中使用元数据

在模板文件中使用 `@meta::` 标记来定义元数据：

```phtml
<!-- @meta::namespace.layout_name -->
<div class="layout">
    <!-- 布局内容 -->
</div>
```

系统会自动扫描这些标记并存储到数据库中。

### 获取元数据

在代码中获取元数据：

```php
use Weline\Meta\Model\Meta;
use Weline\Framework\Manager\ObjectManager;

$metaModel = ObjectManager::getInstance(Meta::class);
$meta = $metaModel->clearQuery()
    ->where(Meta::fields_NAMESPACE, 'YourModule')
    ->where(Meta::fields_META_TYPE, 'layout')
    ->where(Meta::fields_META_IDENTIFY, 'layout_name')
    ->find()
    ->fetch();
```

## 使用场景

### 1. 主题布局管理

定义主题的各种布局模板：

```json
{
    "meta": {
        "base_path": "Weline_Theme::view/frontend/templates",
        "namespace": {
            "layouts": {
                "name": "布局模板",
                "description": "定义网站的各种布局模板",
                "type": "layout",
                "path": "layouts"
            }
        }
    }
}
```

### 2. 组件库管理

定义可复用的组件：

```json
{
    "meta": {
        "base_path": "YourModule::view/components",
        "namespace": {
            "components": {
                "name": "组件库",
                "description": "可复用的页面组件",
                "type": "component",
                "path": ""
            }
        }
    }
}
```

### 3. API 端点管理

定义 API 端点元数据：

```json
{
    "meta": {
        "base_path": "YourModule::Api",
        "namespace": {
            "endpoints": {
                "name": "API端点",
                "description": "RESTful API 端点定义",
                "type": "api",
                "path": ""
            }
        }
    }
}
```

## 扫描机制

### 自动扫描

系统会在以下情况自动扫描元数据：

1. **系统升级时**: 运行 `setup:upgrade` 命令时
2. **模块安装时**: 新模块安装完成后
3. **手动触发**: 运行 `php bin/w meta:collect` 命令

### 扫描规则

- 扫描 `base_path` 指定的目录及其子目录
- 查找模板文件中的 `@meta::` 标记
- 提取元数据信息并存储到数据库
- 支持增量更新，已存在的元数据会被更新

## 最佳实践

1. **命名规范**: 使用清晰、有意义的命名空间和标识符
2. **路径规范**: 使用相对于模块根目录的相对路径
3. **文档完整**: 为每个命名空间提供详细的描述信息
4. **版本管理**: 在元数据中包含版本信息（通过 meta_data 字段）
5. **选项列表**: 对于有固定选项的类型，提供完整的 options 列表

## 常见问题

### Q: 如何知道我的元数据是否被扫描到？

A: 运行 `php bin/w meta:collect` 命令后，可以查看数据库 `w_meta` 表，或者查看生成的 `generated/extends.php` 文件。

### Q: 多个模块可以定义相同的命名空间吗？

A: 可以，但建议使用模块名作为命名空间前缀，避免冲突。例如：`YourModule_Layouts`。

### Q: 元数据文件可以放在多个位置吗？

A: 可以，但每个位置都需要单独的 `@meta.json` 文件定义。

### Q: 如何更新已存在的元数据？

A: 修改 `@meta.json` 文件或模板中的 `@meta::` 标记，然后重新运行扫描命令即可。

## 相关文档

- [Meta 完整实现方案](../../Meta/doc/完整实现方案.md)
- [Meta 扫描命令说明](../../Meta/doc/完整实现方案.md)
