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

- 模块清单显式依赖 `Weline_Framework`、`Weline_Backend` 与 `Weline_I18n`；EAV 后台入口和本地化模型不能依靠未声明的隐式安装顺序。
- Eav 模块内的传统实体可继续继承 `Weline\Eav\EavModel`。其他模块的新实体不得继承或引用 Eav 内部模型：实现 `Weline\Eav\Api\Entity\EntityDefinitionInterface`，通过 `EntityAttributeStoreInterface` 进行属性声明、值读写和动态值表装配。
- EAV 实体注册不是手动建表后就结束。`Observer/UpgradeDefaultAttribute.php` 会在升级流程里扫描激活模块 `Model/` 下实现旧 `EavInterface` 或公开 `EntityDefinitionInterface` 的类，写入 `eav_entity`，并为每个实体兜底创建 `default` 属性集与属性组。
- `attribute_id` 和 `eav_entity_id` 不是一回事：
  `attribute_id` 是属性行主键；
  `eav_entity_id` 是 `eav_entity` 表主键；
  `EavAttribute::getId()` 走的是框架联合主键首字段语义，不能拿它替代 `getAttributeId()` 去操作值表。
- 值表不是固定一张。`Model/EavAttribute/Type/Value.php` 会按 `eav_{entity_code}_{type_code}` 计算值表名，表结构在安装阶段按实体和类型批量创建。
- EAV 核心表由 `SchemaRegistry` 和 `Schema/*` 统一管理，入口在 `Model/EavEntity::install()`。不要自己再造一套 EAV 基础表，也不要回退到手改 `generated/` 或旧式升级脚本。
- Setup 向 Framework 发布的公开边界是 `Weline\Eav\Api\SchemaProvider`：它实现 Framework 契约、通过 `ownerModuleName()` 自述 `Weline_Eav`，再在模块内部调用 `SchemaRegistry`。Framework Setup 不得反向引用 Eav 内部类或硬编码模块名。
- `getAttributeGroup()` 在组不存在时会自动创建组；`default` 组/集是系统兜底语义，不要把“未配置组”理解成“没有 default”。
- 属性前台行为靠元数据字段驱动：`frontend_is_visible`、`frontend_is_filterable`、`frontend_is_searchable`、`data_is_multiple`、`data_has_option`。需要过滤/搜索能力时，先改属性元数据，再接消费逻辑。
- EAV 的本地化描述模型统一继承公开的 `Weline\I18n\Api\Localization\LocalModel`；旧 `Weline\I18n\LocalModel` 只用于历史兼容，新代码不得引用旧内部命名空间。
- 后台列表手动关联本地化描述表时，关联列必须取描述模型的 `schema_fields_ID`，不得假定它与业务主表或 EAV 元数据表的主键同名。
- 后台属性表单的当前用户草稿只通过 `Weline\Backend\Api\UserData\BackendCurrentUserDataInterface` 读取和清理 `attribute` scope；Eav 不得查询 Backend 的用户数据 ORM 模型。
- OffCanvas / iframe 外部嵌入（请求带 `isIframe`）或显式 `embed=1` 时，后台页面控制器在 `__init` 中切换为 `default.blank`，避免嵌套完整后台顶栏/侧栏。独立打开菜单入口仍使用 `default.default`。

## 跨模块公开契约

- `EntityDefinitionInterface` 只描述实体 code、名称、主键类型和长度，不继承 Eav 内部类。
- `AttributeDefinition` 是属性声明输入；`AttributeRecord` 是不可变元数据投影，不暴露 ORM 对象或表字段常量。
- `EntityAttributeStoreInterface` 是实体属性运行时边界。消费模块通过 Framework `RuntimeProviderResolver` 获取，不得直接实例化 `Eav\Service` 或引用 `Eav\Model`。
- `AttributeOptionDefinition` 是属性选项注册输入，`AttributeOptionRecord` 是只读不可变投影；跨模块通过 `AttributeOptionStoreInterface` 注册或查询选项，不得操作内部 `EavAttribute\Option` ORM 模型。
- `AttributeTypeDefinition` 是属性类型注册输入，`AttributeTypeRecord` 是只读不可变投影；安装模块通过 `AttributeTypeRegistryInterface` 注册或查询属性类型，不得跨模块操作 `Eav\Model\EavAttribute\Type`。
- 跨模块解析属性联动选项使用
  `Weline\Eav\Api\Attribute\AttributeDependenceResolverInterface`。`resolve()` 必须接收
  `eav_entity_id`、`dependence_attribute`、`dependence_value`、`attribute`，
  可选 `attribute_value`；实现分别按 `eav_entity_id + attribute` 与
  `eav_entity_id + dependence_attribute` 精确定位当前属性和依赖属性，
  返回最多 500 项的纯 `array<int|string, scalar>` 选项映射。mixed 输入只接受标量、
  Stringable 或最多 500 项的标量列表，单个字符串最多 64 KiB。输入错误抛
  `InvalidArgumentException`，属性、类型、类型模型或返回值契约错误抛
  `DomainException`；下游模型异常只对外返回固定错误，SQL、路径等原始详情只进入服务端
  诊断和 previous exception。消费模块必须通过 Framework `RuntimeProviderResolver` 获取该契约，
  不得构造 Eav Controller、反射 Controller 或直接访问 Eav ORM 模型。
- 可视化编辑器读取实体、属性和选项列表时使用
  `Weline\Eav\Api\Options\EavOptionsQueryInterface`。接口接收请求参数数组并返回与
  `/weline/eav/api/options*` 一致的纯数组 payload；HTTP Controller 和可选 Theme 集成
  共用同一 Eav Service，调用模块不得构造 Eav Controller 或 Model。
- 动态值表仍使用 `eav_{entity_code}_{type_code}`。P1B-005 起值表含 typed Scope 固定列：
  `scope_kind/website_id/website_code/store_code/channel_code/is_cleared/locale`。
  `scope_kind IS NULL` 为遗留行：`replaceValue` 仅操作遗留行；`readValue` 先读遗留行，
  无遗留行时只兼容回退到 typed global（绝不读取 Website/Store/Channel），保证 MIG-P1A
  后旧 reader 可读且不跨站。空串/null **不得**猜成 `cleared`。
  typed 读写走 `readScopedValue` / `writeScopedValue` / `clearScopedValue`（`ScopeIdentity` + locale）；
  `cleared` 阻断父 Scope 与 locale 回退。迁移工具：`php bin/w eav:scope:migrate help|preflight|ensure-columns`；行级 apply 归 `php bin/w scope:migrate-p1a apply --database=mig_clone_*`。

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
