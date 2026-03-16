---
name: acl-permission-system
description: ACL 权限系统。menu.xml 菜单、#[Acl] 属性、父子级关系、type=menus/pc、菜单显示排查。
globs:
  - "**/etc/backend/menu.xml"
  - "**/Controller/**/*.php"
alwaysApply: false
---

# acl-permission-system（极简版）

## 何时使用

- 创建后台菜单（menu.xml）
- 控制器使用 #[Acl]
- 排查菜单消失、层级错乱
- 理解 ACL 父子级关系

## 必做

- menu.xml 用嵌套 `<menu>` 表示层级，子菜单继承父 source
- 控制器类 #[Acl] 的 source_id 与 menu 项一致可继承 parent
- 方法 #[Acl] 的 parent_source 默认为类 source_id
- 只有 type=menus 才在菜单显示，type=pc 仅权限控制

## 最小示例

```xml
<menu source="Vendor_Module::main" title="我的模块" parent="Weline_Backend::content_management">
    <menu source="Vendor_Module::sub" title="子功能" action="module/backend/controller/index"/>
</menu>
```

## 禁止

- 菜单项不指定 parent 导致挂载错误
- 混淆 menus 与 pc 类型
