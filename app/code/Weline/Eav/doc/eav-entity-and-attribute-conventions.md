# EAV 实体与属性约定

## 1. 实体声明

业务模块要接入 EAV，入口不是随便建几张表，而是定义一个继承 `Weline\Eav\EavModel` 的业务模型，并提供：

- `entity_code`
- `entity_name`
- `eav_entity_id_field_type`
- `eav_entity_id_field_length`

这些声明由 `app/code/Weline/Eav/EavModel.php` 的 `__init()` 强校验。缺任一项都不是“警告”，而是直接异常。

## 2. 实体注册时机

实体注册靠升级流程中的 `app/code/Weline/Eav/Observer/UpgradeDefaultAttribute.php` 完成。它会：

- 读取所有激活模块。
- 扫描每个模块 `Model/` 目录类。
- 识别实现 `EavInterface` 的实例化类。
- 写入 `eav_entity`。
- 为该实体兜底创建 `default` 属性集和 `default` 属性组。

这意味着：

- 新 EAV 实体通常要放在模块 `Model/` 下，避免扫描链路命不中。
- 只写类不执行 `php bin/w setup:upgrade`，实体注册不会生效。

## 3. ID 语义

开发里最容易写错的是三个 ID：

- 业务实体 ID：你自己的主表主键，比如 `product_id`。
- `eav_entity_id`：`eav_entity` 表里的实体定义主键。
- `attribute_id`：`eav_attribute` 表里的属性主键。

重点：

- `EavAttribute::getAttributeId()` 才是属性值表、选项表应该引用的主键。
- `EavAttribute::getId()` 不能替代 `getAttributeId()`。
- `EavModel::getEavEntityId()` 返回的是当前实体定义 ID，不是业务主表 ID。

## 4. 值表规则

属性值不是全部堆在一张通用表，而是由 `app/code/Weline/Eav/Model/EavAttribute/Type/Value.php` 按：

`eav_{entity_code}_{type_code}`

动态决定目标值表。

例如一个实体存在多种属性类型时，不同类型会分表存储。开发时不要：

- 自己手写固定值表名。
- 假设所有属性值都在同一张表。
- 在不知道属性类型的情况下直接拼值表 SQL。

## 5. 核心表与类型表

EAV 核心表由 `SchemaRegistry` 统一编排，入口在：

- `app/code/Weline/Eav/Model/EavEntity.php`
- `app/code/Weline/Eav/Schema/SchemaRegistry.php`

属性本身的前台语义也来自元数据字段，而不是页面自己猜：

- `frontend_is_visible`
- `frontend_is_filterable`
- `frontend_is_searchable`
- `data_is_multiple`
- `data_has_option`

属性类型基础数据见：

- `app/code/Weline/Eav/Schema/EavAttributeTypeSchema.php`

如果新增类型、修改前台渲染或 swatch 能力，要同时检查类型初始化数据、值表字段能力和消费页面。

## 6. 过滤与搜索

前台筛选、搜索、分组选项不要重复发明一遍。优先走：

- `app/code/Weline/Eav/Service/AttributeFilterService.php`

它已经按 `frontend_is_filterable` / `frontend_is_searchable` 提供：

- 可过滤属性元数据
- 可搜索属性元数据
- 属性值计数
- 按属性过滤实体 ID

## 7. 开发反例

- 反例：把颜色、尺码这类高变化字段直接塞回业务主表。
- 反例：改 `view/tpl` 看起来“生效更快”。
- 反例：后台新增属性后不跑升级，就认为系统应该自动识别。
- 反例：按 `code` 写死前台筛选逻辑，但属性元数据没打 `frontend_is_filterable` 标记。
