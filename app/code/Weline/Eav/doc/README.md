# Weline_Eav 模块文档

## 开发前先读

1. `app/code/Weline/Eav/doc/AI-INDEX.md`
2. `app/code/Weline/Eav/doc/eav-entity-and-attribute-conventions.md`
3. 本模块实际命中的 `Model/`、`Schema/`、`Service/`、`Controller/` 源码

## 模块定位

`Weline_Eav` 不是“任意动态字段工具箱”，而是框架的实体属性系统底座。它同时负责：

- EAV 实体注册：把业务模型声明成可识别的 EAV 实体。
- 属性元数据管理：属性、属性集、属性组、属性类型、属性选项。
- 值表创建与路由：按 `实体 + 类型` 动态落到值表。
- 后台管理面：属性、属性集、属性组的管理界面与 API。
- 前台检索支撑：可过滤、可搜索属性元数据与属性值统计。

## 核心约定

- 业务 EAV 实体必须继承 `Weline\Eav\EavModel`，并提供 `entity_code`、`entity_name`、`eav_entity_id_field_type`、`eav_entity_id_field_length`。`EavModel::__init()` 会强校验这些声明。
- EAV 实体注册不是手动建表后就结束。`Observer/UpgradeDefaultAttribute.php` 会在升级流程里扫描激活模块 `Model/` 下实现 `EavInterface` 的类，写入 `eav_entity`，并为每个实体兜底创建 `default` 属性集与属性组。
- `attribute_id` 和 `eav_entity_id` 不是一回事：
  `attribute_id` 是属性行主键；
  `eav_entity_id` 是 `eav_entity` 表主键；
  `EavAttribute::getId()` 走的是框架联合主键首字段语义，不能拿它替代 `getAttributeId()` 去操作值表。
- 值表不是固定一张。`Model/EavAttribute/Type/Value.php` 会按 `eav_{entity_code}_{type_code}` 计算值表名，表结构在安装阶段按实体和类型批量创建。
- EAV 核心表由 `SchemaRegistry` 和 `Schema/*` 统一管理，入口在 `Model/EavEntity::install()`。不要自己再造一套 EAV 基础表，也不要回退到手改 `generated/` 或旧式升级脚本。
- `getAttributeGroup()` 在组不存在时会自动创建组；`default` 组/集是系统兜底语义，不要把“未配置组”理解成“没有 default”。
- 属性前台行为靠元数据字段驱动：`frontend_is_visible`、`frontend_is_filterable`、`frontend_is_searchable`、`data_is_multiple`、`data_has_option`。需要过滤/搜索能力时，先改属性元数据，再接消费逻辑。

## 典型开发流程

1. 在业务模块定义主实体表模型，常规字段继续走模型 `#[Col]`/`#[Index]`。
2. 让该模型继承 `EavModel`，补齐实体声明常量或属性。
3. 执行 `php bin/w setup:upgrade`，让实体注册、核心表/值表安装和默认组集兜底生效。
4. 通过 `addAttribute()`、属性模型或后台管理面新增属性，不要把属性直接硬编码进业务主表。
5. 做前台筛选/搜索时，优先使用 `AttributeFilterService` 暴露的元数据和过滤能力，而不是自己拼散乱 SQL。

## 常见误区

- 把业务主表主键当成 `eav_entity_id`。
- 在值表里使用 `getId()` 代替 `getAttributeId()`。
- 新增属性类型后只改模型，不补 `Schema/*` 或安装链路。
- 删除属性时忘记决定是否同时清值；需要连带删除值时显式走 `unsetAttribute($code, true)`。
- 遇到后台页面问题直接改 `view/tpl`；这里只能改 `view/templates` 与静态源文件。

## 源码锚点

- `app/code/Weline/Eav/EavModel.php`
- `app/code/Weline/Eav/Model/EavEntity.php`
- `app/code/Weline/Eav/Model/EavAttribute.php`
- `app/code/Weline/Eav/Model/EavAttribute/Type/Value.php`
- `app/code/Weline/Eav/Schema/SchemaRegistry.php`
- `app/code/Weline/Eav/Service/AttributeFilterService.php`
- `app/code/Weline/Eav/Observer/UpgradeDefaultAttribute.php`
