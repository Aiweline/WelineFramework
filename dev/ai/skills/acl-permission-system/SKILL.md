---
name: acl-permission-system
description: |
  ACL (Access Control List) permission system for Weline Framework.
  
  MUST use when:
  - Creating menus in menu.xml
  - Using #[Acl] attribute on controllers/methods
  - Understanding ACL parent-child relationships
  - Troubleshooting menu display issues
  - Working with weline_acl table
  
  Keywords: ACL, 权限, permission, menu, 菜单, menu.xml, #[Acl], source_id, parent_source, type, menus, pc, 权限控制, 菜单消失, 菜单层级
globs:
  - "**/etc/backend/menu.xml"
  - "**/Controller/**/*.php"
  - "**/Acl/**/*.php"
alwaysApply: false
---

# ACL 权限系统技能

## 何时使用

- 创建后台菜单 (`etc/backend/menu.xml`)
- 在控制器上使用 `#[Acl]` 属性
- 排查菜单显示问题（菜单消失、层级错乱）
- 理解 ACL 父子级关系

## ACL 父子级关系（核心概念）

**ACL 的三层父子级结构：**

```
menu.xml 定义的菜单项
    └── 控制器类上的 #[Acl]（继承 menu.xml 的父级）
        └── 控制器方法上的 #[Acl]（父级 = 控制器类的 source_id）
```

### 关键规则

1. **menu.xml 定义菜单层级**
   - `parent` 属性指定父菜单的 `source`
   - `type` 自动设为 `menus`

2. **控制器类 `#[Acl]` 继承菜单层级**
   - 如果 `source_id` 与 menu.xml 中某项相同，自动继承其 `parent_source`
   - 如果 `#[Acl]` 显式指定了 `parent_source`，以显式指定为准
   - 如果未指定且 menu.xml 也没有定义，`parent_source` 为空

3. **控制器方法 `#[Acl]` 的父级是类**
   - 方法的 `parent_source` 自动设为控制器类的 `source_id`
   - 方法可以显式指定其他 `parent_source` 覆盖默认行为

## ACL 类型 (type 字段)

| type | 用途 | 来源 |
|------|------|------|
| `menus` | 菜单项，在后台导航显示 | menu.xml |
| `pc` | 权限控制点，不显示在菜单 | 控制器 #[Acl] |

**重要**：`type='menus'` 的记录才会在后台菜单中显示！

## 1. 创建后台菜单 (menu.xml)

### 文件位置

```
app/code/Vendor/Module/etc/backend/menu.xml
```

### 基本结构

```xml
<?xml version="1.0" encoding="UTF-8"?>
<menus xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
       xs:noNamespaceSchemaLocation="urn:weline:module:Weline_Backend::etc/xsd/menu.xsd">
    
    <!-- 顶层菜单（挂载到系统菜单下） -->
    <add source="Vendor_Module::main_menu" 
         name="main_menu" 
         title="我的模块" 
         action=""
         parent="Weline_Backend::business_module" 
         icon="mdi mdi-folder" 
         order="100"/>
    
    <!-- 子菜单 -->
    <add source="Vendor_Module::sub_menu" 
         name="sub_menu" 
         title="子功能" 
         action="module/backend/controller/index"
         parent="Vendor_Module::main_menu" 
         icon="mdi mdi-file" 
         order="10"/>
</menus>
```

### 属性说明

| 属性 | 必填 | 说明 |
|------|------|------|
| `source` | ✓ | 唯一标识符，格式：`Vendor_Module::menu_name` |
| `name` | ✓ | 菜单名称（用于内部引用） |
| `title` | ✓ | 显示标题（支持 i18n） |
| `action` | | 路由地址，空表示仅作为分组 |
| `parent` | | 父菜单的 `source`，空表示根级菜单 |
| `icon` | | 图标 CSS 类名 |
| `order` | | 排序值，数字越小越靠前 |

### 常用父级菜单

| 父级 source | 说明 |
|-------------|------|
| `Weline_Backend::dashboard` | 面板 |
| `Weline_Backend::business_module` | 业务模块 |
| `Weline_Backend::system_menu` | 系统管理 |
| `Weline_Backend::system_settings` | 系统设置 |
| `Weline_Backend::system_service` | 系统服务 |
| `Weline_Backend::system_module` | 模组 |
| `Weline_Backend::system_dev_configuration` | 开发工具 |

## 2. 控制器权限 (#[Acl] 属性)

### 类级别权限

```php
<?php
namespace Vendor\Module\Controller\Backend;

use Weline\Framework\Acl\Acl;

// source_id 与 menu.xml 中的 source 相同时，自动继承菜单层级
#[Acl('Vendor_Module::sub_menu', '子功能', 'mdi-file', '子功能管理')]
class MyController extends \Weline\Framework\App\Controller\BackendController
{
    public function index() { ... }
}
```

### 方法级别权限

```php
#[Acl('Vendor_Module::sub_menu', '子功能', 'mdi-file')]
class MyController extends \Weline\Framework\App\Controller\BackendController
{
    // 方法的 parent_source 自动指向类级别的 source_id
    #[Acl('Vendor_Module::sub_menu_create', '新建', 'mdi-plus', '新建功能')]
    public function create() { ... }
    
    #[Acl('Vendor_Module::sub_menu_edit', '编辑', 'mdi-pencil', '编辑功能')]
    public function edit() { ... }
}
```

### #[Acl] 参数

```php
#[Acl(
    source_id: 'Vendor_Module::permission_name',  // 唯一标识符
    source_name: '权限名称',                       // 显示名称
    icon: 'mdi-icon-name',                        // 图标
    document: '权限说明文档',                      // 可选，说明
    parent_source: '',                            // 可选，显式指定父级
    rewrite: ''                                   // 可选，重写
)]
```

## 3. 常见问题排查

### 菜单不显示

**检查项：**

1. **type 是否为 menus**
   ```sql
   SELECT source_id, type, parent_source FROM weline_acl 
   WHERE source_id = 'Vendor_Module::menu_item';
   ```
   - 如果 `type='pc'`，说明被控制器收集覆盖了

2. **parent_source 是否正确**
   - 如果 `parent_source` 为空但应该有父级，可能被覆盖
   - 如果 `parent_source` 指向不存在的 source_id，该菜单会变成孤儿

3. **is_enable 是否为 1**
   - 禁用的模块其菜单 `is_enable=0`

### 菜单层级错乱

**原因：** `parent_source` 被控制器 `#[Acl]` 收集时覆盖

**解决方案：**
1. 确保 menu.xml 在控制器收集**之前**运行（系统已自动处理）
2. 控制器 `#[Acl]` 不要显式指定空的 `parent_source`
3. 如需覆盖，显式指定正确的 `parent_source`

### 运行 s:up 后菜单恢复

**说明：** 这是正常现象。`s:up` 会重新收集 menu.xml 和控制器权限，现在的代码会正确保留 menu.xml 设置的 `parent_source` 和 `type`。

## 4. 数据库表结构

### weline_acl 表关键字段

| 字段 | 说明 |
|------|------|
| `source_id` | 唯一标识符（主键） |
| `source_name` | 显示名称 |
| `parent_source` | 父级的 source_id |
| `type` | 类型：`menus`（菜单）或 `pc`（权限控制） |
| `route` | 关联路由 |
| `class` | 关联控制器类 |
| `method` | 关联方法名 |
| `module` | 所属模块 |
| `is_enable` | 是否启用 |
| `order` | 排序值 |

## 5. 最佳实践

### DO ✓

- 在 menu.xml 中定义完整的菜单层级
- 控制器 `#[Acl]` 使用与 menu.xml 相同的 `source_id` 实现菜单与权限绑定
- 方法级别权限不需要显式指定 `parent_source`，自动继承类级别

### DON'T ✗

- 不要在控制器 `#[Acl]` 中显式指定空的 `parent_source` 覆盖 menu.xml
- 不要定义指向不存在 `source_id` 的 `parent_source`
- 不要手动修改 `weline_acl` 表（应通过 menu.xml 和 `s:up` 更新）

## 6. 调试命令

```bash
# 重新收集菜单和权限
php bin/w s:up

# 查看菜单树
php bin/w menu:list

# 清理缓存
php bin/w cache:clear
```
