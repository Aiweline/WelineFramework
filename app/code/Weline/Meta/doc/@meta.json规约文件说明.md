# @meta.json 规约文件说明

## 概述

`@meta.json` 是 Meta 模块的**只读规约文件**，用于定义元数据的层级结构、默认值和选项列表。在使用 Meta 功能之前，必须先定义 `@meta.json` 规约文件。

**重要说明**：
- `@meta.json` 是**只读的规约文件**，不会被修改
- Meta 模块会自动扫描 `@meta.json` 文件，读取规约定义
- 扫描到的元数据信息会**存储到 `meta` 表中**，不会回填到 `@meta.json` 文件
- `options` 的自动生成是基于目录结构扫描，但结果存储在数据库中，不会修改 `@meta.json` 文件

## 文件位置

### Extends 机制

`@meta.json` 文件使用 **Extends 机制** 放置在模块的 `extends` 目录下：

**路径格式**：`app/code/{ModuleName}/extends/Weline_Meta/{ModuleName}/@meta.json`

**示例**：
- Theme 模块：`app/code/Weline/Theme/extends/Weline_Meta/Weline_Theme/@meta.json`
- 其他模块：`app/code/{YourModule}/extends/Weline_Meta/{YourModule}/@meta.json`

### 目录结构

```
app/code/
  └── Weline/
      └── Theme/
          └── extends/
              └── Weline_Meta/
                  └── Weline_Theme/
                      └── @meta.json
```

## 文件格式

### 基本结构

```json
{
  "meta": {
    "base_path": "ModuleName::相对路径",
    "{namespace}": {
      "default": "默认值",
      "name": "显示名称",
      "description": "描述信息",
      "{group}": {
        "default": "默认值",
        "name": "显示名称",
        "description": "描述信息",
        "{field}": {
          "name": "字段名称",
          "description": "字段描述",
          "options": {},
          "values": [],
          "default": "默认值",
          "type": "字段类型",
          "required": true,
          "placeholder": "占位符",
          "show": true
        }
      }
    }
  }
}
```

### 字段说明

#### 顶层字段

- **`base_path`**（必需）：基础路径，格式为 `ModuleName::相对路径`，用于扫描目录下的文件
  - 示例：`"Weline_Theme::view/theme/"`

#### 节点字段

- **`default`**：默认值
- **`name`**：显示名称（用于翻译）
- **`description`**：描述信息（用于翻译）
- **`options`**：选项列表（对象格式，键值对）
  - 格式：`{"key": "显示名称", "key2": "显示名称2"}`
  - 示例：`{"account": "个人中心布局", "homepage": "首页布局"}`
- **`values`**：选项列表（数组格式）
  - 格式：`["key1", "key2", "key3"]`
  - 示例：`["account", "homepage", "product"]`
- **`type`**：字段类型
  - 可选值：`select`、`text`、`textarea`、`number`、`boolean` 等
- **`required`**：是否必填（布尔值）
- **`placeholder`**：占位符文本
- **`show`**：是否显示（布尔值）

## 层级结构规则

### 组（Group）和值（Value）

**规则**：**拥有子元素的节点被认为是组（group），没有子元素的节点是值（value）**。

**示例**：
```json
{
  "meta": {
    "theme": {                    // 组：theme（因为有子元素 frontend）
      "frontend": {               // 组：frontend（因为有子元素 layout）
        "layout": {               // 值：layout（如果没有子元素，或者子元素是配置项）
          "name": "布局命名空间",
          "description": "布局命名空间，用于描述布局的名称",
          "options": {},
          "account": {            // 值：account（如果目录下有 account 目录，会自动扫描）
            "name": "个人中心布局",
            "description": "个人中心相关布局"
          }
        }
      }
    }
  }
}
```

### Meta Key 格式

根据层级结构，Meta Key 格式为：

```
@meta::{namespace}.{group1}.{group2}.{field}
```

**示例**：
- `@meta::theme.frontend.layout.account`
- `@meta::theme.frontend.layout.account.name`
- `@meta::theme.frontend.layout.account.description`

## 自动扫描机制

### 扫描流程

1. **读取规约文件**：Meta 模块读取 `@meta.json` 文件，获取规约定义
2. **读取 base_path**：从规约文件中读取 `base_path`
3. **扫描目录结构**：根据 `base_path` 扫描目录下的子目录和文件
4. **生成 options**：将子目录名称作为选项键，自动生成 `options` 和 `values`
5. **存储到数据库**：将扫描到的元数据信息**存储到 `meta` 表中**，**不会回填到 `@meta.json` 文件**

**注意**：`@meta.json` 文件是只读的规约文件，扫描结果只会存储在数据库中，不会修改原文件。

### 目录结构示例

**目录结构**：
```
view/theme/frontend/layouts/
  ├── account/
  │   ├── auth.phtml
  │   ├── dashboard.phtml
  │   └── profile.phtml
  ├── homepage/
  │   └── default.phtml
  └── product/
      └── list.phtml
```

**自动生成的 options**：
```json
{
  "layout": {
    "options": {
      "account": "个人中心布局",
      "homepage": "首页布局",
      "product": "产品布局"
    },
    "values": ["account", "homepage", "product"]
  }
}
```

### 扫描规则

1. **只扫描目录**：只扫描子目录，不扫描文件
2. **识别 .phtml 文件**：如果子目录下有 `.phtml` 文件，自动添加到 options
3. **命名规则**：目录名称作为选项键，目录名称的首字母大写作为显示名称（可配置）
4. **递归扫描**：支持递归扫描多级目录
5. **存储位置**：扫描结果存储在 `meta` 表中，不会修改 `@meta.json` 文件

## 默认选中机制

### 工作原理

当 meta 渲染时，如果提供了布局参数，系统会自动选中对应的选项：

```php
<!-- 如果当前布局是 account/auth，会自动选中 account -->
<w:meta type="select" prefix="theme.frontend.layout" layout="account/auth" />
```

### 匹配规则

1. **提取布局类型**：从 `layout` 参数中提取布局类型（如 `account/auth` → `account`）
2. **查找 options**：在规约文件的 `options` 中查找匹配的键
3. **自动选中**：如果找到匹配的键，自动设置为选中状态

## 完整示例

### Theme 模块的 @meta.json

**文件位置**：`app/code/Weline/Theme/extends/Weline_Meta/Weline_Theme/@meta.json`

```json
{
  "meta": {
    "base_path": "Weline_Theme::view/theme/",
    "theme": {
      "default": "Weline_Theme",
      "name": "主题命名空间",
      "description": "主题命名空间，用于主题模块的命名空间",
      "frontend": {
        "default": "前端组",
        "name": "前端组",
        "description": "前端组，用于给元素据分组用的，方便维护",
        "layout": {
          "name": "布局命名空间",
          "description": "布局命名空间，用于描述布局的名称",
          "options": {
            "account": "个人中心布局",
            "homepage": "首页布局",
            "product": "产品布局",
            "category": "分类布局",
            "cart": "购物车布局",
            "checkout": "结账布局",
            "default": "默认布局"
          },
          "values": ["account", "homepage", "product", "category", "cart", "checkout", "default"],
          "default": "default",
          "type": "select",
          "required": true,
          "placeholder": "请选择布局",
          "show": true
        },
        "partials": {
          "name": "部件",
          "description": "部件命名空间，用于描述部件的名称",
          "options": {
            "header": "头部部件",
            "footer": "底部部件",
            "sidebar": "侧边栏部件",
            "breadcrumb": "面包屑部件"
          },
          "values": ["header", "footer", "sidebar", "breadcrumb"],
          "default": "default",
          "type": "select",
          "required": true,
          "placeholder": "请选择部件",
          "show": true
        }
      },
      "backend": {
        "default": "后端组",
        "name": "后端组",
        "description": "后端组，用于给元素据分组用的，方便维护",
        "layout": {
          "name": "布局命名空间",
          "description": "布局命名空间，用于描述布局的名称",
          "options": {
            "dashboard": "仪表盘布局",
            "default": "默认布局",
            "minimal": "极简布局"
          },
          "values": ["dashboard", "default", "minimal"],
          "default": "default",
          "type": "select",
          "required": true,
          "placeholder": "请选择布局",
          "show": true
        }
      }
    }
  }
}
```

## 使用示例

### 在模板文件中使用

```php
<?php
/**
 * 布局：个人中心 - 认证页面布局
 * 
 * 登录/注册等认证页面的专用布局
 * 字段
 * @meta::theme {default=Weline_Theme,name="主题命名空间",description="主题命名空间，用于主题模块的命名空间"}
 * @meta::theme.frontend {default="前端组",name="前端组",description="前端组，用于给元素据分组用的，方便维护"}
 * @meta::theme.frontend.layout.account {default="account",name="个人中心布局",description="个人中心相关布局"}
 * @meta::theme.frontend.layout.account.name {default="个人中心认证页面布局",name="个人中心认证页面布局",description="个人中心认证页面布局，用于描述布局的名称"}
 * @meta::theme.frontend.layout.account.description {default="登录/注册等认证页面的专用布局",name="登录/注册等认证页面的专用布局",description="登录/注册等认证页面的专用布局，用于描述布局的用途"}
 */
?>
```

### 使用 w:meta 标签渲染

```php
<!-- 使用规约定义的元数据，自动选中 account -->
<w:meta type="select" prefix="theme.frontend.layout" layout="account/auth" />

<!-- 读取名称 -->
<w:meta type="translate">theme.frontend.layout.account.name</w:meta>

<!-- 读取描述 -->
<w:meta type="translate">theme.frontend.layout.account.description</w:meta>
```

## 注意事项

1. **必须先定义规约**：在使用 Meta 功能之前，必须先定义 `@meta.json` 规约文件
2. **只读文件**：`@meta.json` 是只读的规约文件，不会被修改，所有扫描结果存储在 `meta` 表中
3. **base_path 格式**：`base_path` 必须使用 `ModuleName::相对路径` 格式
4. **层级结构**：合理规划层级结构，避免过深的嵌套
5. **options 存储**：自动生成的 `options` 存储在数据库中，不会回填到 `@meta.json` 文件
6. **重新扫描**：当目录结构变化时，可以重新扫描，新的 options 会更新到数据库中
7. **命名规范**：使用有意义的命名，便于理解和维护

## 相关文档

- [Meta 模块使用指南](./使用指南.md)
- [w:meta 标签使用说明](../Theme/doc/w-meta标签使用说明.md)
- [组件 Meta 信息格式规范](../Theme/doc/组件Meta信息格式规范.md)

