# SystemConfig Scope 配置树计划

> 模块：`Weline_SystemConfig`
> 状态：规划中
> 关联总计划：[SystemConfig 与 Theme 虚拟布局总计划](./scope-config-theme-layout-master-plan.md)
> 关联 Theme 子计划：[Theme 虚拟布局与产品/分类布局计划](../../Theme/doc/virtual-layout-scope-plan.md)

## 目标

将现有 `Weline_SystemConfig` 升级为统一配置中心。配置系统继续以 `module + area + key` 为基础，新增 `scope + locale` 维度，支持模块筛选、scope 筛选、配置树后台、fallback 读取和 `w_query('system_config', ...)` 查询。

原则上不再新增平行的 `ScopedConfig` 配置系统。适合 key/value 的配置进入 `system_config`；复杂业务对象保留自己的业务表，但必须复用框架统一 Scope 解析和 SystemConfig 的后台/查询入口。

## 现有基础

- `system_config` 表已经有 `key`、`module`、`area`、`v`。
- `SystemConfig` 已提供 `getConfig()`、`getConfigByModule()`、`getConfigMapByModule()`、`setConfig()`。
- 模块已有 `system_config` QueryProvider，支持 `getConfig`、`getConfigs`、`setConfig`。
- 现有缓存已经按 single、module rows、module map、request cache 分层。

## 表结构升级

`system_config` 保留现有字段并新增：

| 字段 | 说明 |
| --- | --- |
| `scope` | scope key，默认 `default`，新写入建议使用规范化三段 scope |
| `locale` | locale key，默认空字符串 |
| `value_type` | `string`、`bool`、`int`、`float`、`json`、`encrypted`、`secret_ref` |
| `is_sensitive` | 是否敏感配置，敏感配置后台脱敏展示 |
| `is_active` | 是否启用该配置值 |
| `metadata` | 少量运行时元信息，配置定义不放这里 |
| `version` | 当前配置行版本号，保存时递增，用于并发冲突检测 |
| `updated_at` / `updated_by` | 最后修改时间和后台用户 |

唯一维度调整为：

```text
module + area + key + scope + locale
```

迁移要求：

- 旧数据补 `scope=default`、`locale=''`、`value_type=string`。
- 旧 API 不传 scope 时写入 `default` scope，避免后台保存被当前站点或店铺污染。
- 旧 `getConfig($key, $module, $area)` 保持可用。
- 给 `module + area + key + scope + locale` 增加唯一索引。
- 给 `module + area + scope + locale`、`module + area + key` 增加查询索引，支撑模块配置树和 fallback 读取。
- 迁移时如果旧数据出现重复 key，保留最近更新或 ID 最大的一行，其余写入配置版本记录，避免静默丢失。

### 配置版本表

SystemConfig 需要支持保存批次和快速回滚。建议新增配置版本表或等价审计表：

| 字段 | 说明 |
| --- | --- |
| `version_id` | 保存批次 ID |
| `module` / `area` / `scope` / `locale` | 批次作用范围 |
| `changes_json` | 本次保存涉及的 key、旧值、新值、旧版本、新版本 |
| `inherit_keys_json` | 本次恢复继承删除的 key |
| `actor_id` / `actor_name` | 操作人 |
| `reason` | 操作备注，可选 |
| `created_at` | 保存时间 |
| `status` | `applied`、`rolled_back`、`failed` |

保存策略：

- 后台每次点击保存生成一个 `version_id`。
- 每个 key 保存前记录旧值、旧 source、旧 row version 和新值。
- 保存成功后返回 `version_id`，后台展示“立即回滚”入口。
- 保存失败必须整体回滚当前事务，不能留下半批次配置。
- 敏感值版本记录不得保存明文，只记录脱敏摘要、secret 引用或加密密文。
- 回滚只恢复该 `version_id` 改动过的 key，不影响之后其他批次修改过且版本号已变化的 key。

版本保存和失败恢复是配置中心的基础能力：

- `saveScopeConfig()` 必须以批次为单位保存，不能逐 key 成功后再静默吞掉失败 key。
- 写入前必须读取并记录当前值、当前来源、当前 row version 和继承状态。
- 任意字段校验、ACL、env-lock、解码、数据库写入失败时，整个批次回滚，并返回失败原因。
- 成功批次必须持久化 `version_id`，后台提示应带“查看变更”和“立即回滚”入口。
- 回滚前必须重新比对当前 row version，避免覆盖后续管理员修改。
- 回滚如果遇到冲突，默认阻止；只有管理员显式选择跳过冲突 key 时才允许部分回滚。
- 回滚操作本身生成新的版本批次，记录 `rollback_of_version_id` 或等价元信息。
- 敏感配置的版本记录只保存脱敏摘要、密文引用或 secret 引用，不能写明文历史。

## 配置模板定义

配置值和配置模板分离。`system_config` 只存值；模块用 PHTML 配置模板声明后台配置页面、配置组和字段。开发者写模板即可，不需要维护复杂数组 schema。

配置模板通过 Weline Extends 模式注册到 `Weline_SystemConfig`，SystemConfig 只读取 Extends 注册表，不在运行时扫描模块目录。

职责边界：

- 模块只提供 PHTML 配置模板，声明 group、field、adapter、meta 和默认值。
- 全局 scope 切换、站点/店铺选择、模块搜索、配置搜索、继承开关、保存、校验、缓存失效和来源解释都由 `Weline_SystemConfig` 完成。
- 模块模板不实现保存接口，不处理 scope 写入，不直接写 `system_config`。
- 业务模块如果需要管理复杂对象，只通过 `<w:config:adapter>` 暴露摘要和管理入口；普通配置保存仍由 SystemConfig 处理。

推荐路径：

```text
app/code/{Vendor}/{Module}/extends/module/Weline_SystemConfig/Config/{area}/{code}.phtml
```

示例：

```text
app/code/Weline/Theme/extends/module/Weline_SystemConfig/Config/frontend/layout.phtml
app/code/WeShop/Payment/extends/module/Weline_SystemConfig/Config/backend/methods.phtml
```

### 模板示例

```phtml
<?php
/**
 * @meta.title {default="主题配置",description="Theme 模块配置中心标题"}
 * @meta.description {default="配置主题布局、虚拟布局入口和前台展示选项。"}
 * @config.area {frontend}
 * @config.sort {30}
 */

$layoutOptions = w_query('theme', 'getLayoutOptions', ['layout_type' => 'product'], 'backend');
?>

<w:config:group code="layout" label="布局配置" description="配置前台页面默认布局和布局管理入口。" sort="10">
    <w:config:field
        key="layout.product.default_option"
        label="默认产品页布局"
        description="产品没有专属布局时使用的默认布局选项。"
        type="select"
        value-type="string"
        default="default"
        scope="global,website,store"
        options="$layoutOptions"
        validation="required|in_options"
    />

    <w:config:field
        key="layout.enable_schedule"
        label="启用定时布局"
        description="允许布局计划在指定时间段内改变有效布局选项。"
        type="switch"
        value-type="bool"
        default="1"
        scope="global,website,store"
    />

    <w:config:adapter
        code="layout.assets"
        label="虚拟布局资产"
        description="管理产品、分类和活动虚拟布局。"
        provider="theme"
        summary-operation="getLayoutAssetSummary"
        manage-url="theme/backend/layout-assets"
    />
</w:config:group>
```

### 标签职责

SystemConfig 提供配置模板专用标签：

| 标签 | 说明 |
| --- | --- |
| `<w:config:group>` | 声明配置组，生成后台分组标题和导航 |
| `<w:config:field>` | 声明一个可保存字段，自动绑定当前显式选择的 scope |
| `<w:config:adapter>` | 声明复杂业务对象入口，只展示摘要和管理入口 |
| `<w:config:hint>` | 展示说明、警告或文档链接，不参与保存 |

字段完整身份仍然是：

```text
module + area + key + scope + locale
```

`key` 可以使用点号组织层级，例如 `layout.product.default_option`，但点号只是 key 命名，不表示 JSON path。

### 模板能力边界

配置模板允许写普通 PHTML 逻辑，例如调用 `w_query()` 获取选项、按模块状态决定是否显示某段配置、渲染说明块。

保存边界只认配置标签：

- 只有 `<w:config:field>` 声明的字段可以写入 `system_config`。
- 普通 `<input>` 不会被 SystemConfig 保存，除非它由配置字段标签生成或显式绑定到字段标签。
- `<w:config:adapter>` 不走普通保存，只跳转或委托到业务模块入口。
- 模板内不能绕过 SystemConfig 直接写数据库配置。

这样保留“想怎么写模板就怎么写”的灵活度，同时保持配置保存、scope 继承、缓存失效和安全校验可控。

### 模板元信息

模板顶部使用 `@meta.*` 和 `@config.*`：

| 标记 | 说明 |
| --- | --- |
| `@meta.title` | 模块配置页标题 |
| `@meta.description` | 模块配置页说明 |
| `@config.area` | 默认 area，缺省由路径 `{area}` 推断 |
| `@config.sort` | 模块配置模板排序 |
| `@config.acl` | 可选 ACL |

配置组和字段展示文案来自标签属性：

```text
label
description
placeholder
options
```

这些文案作为 AI 翻译和 i18n 的 source。SystemConfig 可以提供“AI 翻译配置模板 Meta”能力，把模板中的 `@meta`、group label、field label、description、options 自动生成或补齐到模块 i18n。

### Scope 层级就是嵌套关系

配置模板只定义 group 和 field，不再设计复杂的配置嵌套树。后台真正的层级来自 scope：

```text
全局
  站点
    店铺
```

同一个模板在不同 scope 下渲染同一组字段，每个字段显示当前 scope 覆盖值、继承来源和 fallback 链。开发者不需要在模板里手写全局、站点、店铺三套表单。

### 字段属性

`<w:config:field>` 支持的关键属性：

| 属性 | 说明 |
| --- | --- |
| `key` | 模块内配置 key |
| `label` | 字段名称 |
| `description` | 字段说明 |
| `type` | 后台控件类型 |
| `value-type` | 存储值类型 |
| `default` | 模块默认值 |
| `scope` | 允许的 scope 级别，缺省 `global` |
| `locale` | 是否允许 locale 覆盖 |
| `required` | 是否必填 |
| `options` | 静态选项或模板变量 |
| `validation` | 保存前校验规则 |
| `sensitive` | 是否敏感 |
| `env-lock` | env 锁定 key 或规则 |
| `depends` | 后台显示依赖 |

`scope` 允许值：

```text
global
global,website
global,website,store
```

只有字段显式声明 `website` 或 `store`，后台才允许在站点级或店铺级保存覆盖。

### 控件类型与值类型

`type` 控制后台表单：

```text
text, textarea, number, switch, checkbox, radio, select, multiselect,
color, date, datetime, file, image, password, secret, json, code
```

`value-type` 控制存储和解码：

```text
string, bool, int, float, json, encrypted, secret_ref
```

`type=password` 或 `type=secret` 必须搭配 `value-type=encrypted` 或 `secret_ref`，不能明文存储。

### Extends 收集

`SystemConfigTemplateRegistry` 负责读取配置模板：

- 调用 `ExtendsData::getExtendedBy('Weline_SystemConfig')`。
- 只接受 `relative_path` 命中 `extends/module/Weline_SystemConfig/Config/` 的 PHTML。
- 从 Extends 注册信息读取 `source_module`、`source_file`、`relative_path`。
- 不在 Web 运行时扫描模块文件系统。
- 模块启停、`setup:upgrade`、Extends registry 刷新或缓存清理时更新模板列表。

如果 Extends registry 过期，后台提示需要刷新扩展注册表，不回退到全仓扫描。

### 解析与校验

`SystemConfigTemplateParser` 负责解析：

- 解析模板顶部 `@meta.*`、`@config.*`。
- 解析 `<w:config:group>`、`<w:config:field>`、`<w:config:adapter>` 标签。
- 校验 area、group code、field key、type、value-type、scope、validation、sensitive。
- 生成后台配置树和字段保存白名单。
- 将解析结果缓存起来，缓存 key 包含模板文件 mtime 和 Extends registry mtime。

解析模式和渲染模式必须分开：

- 解析模式只提取 `@meta` 和配置标签，不执行可能产生副作用的业务逻辑。
- 渲染模式可以执行模板中的展示逻辑，例如调用只读 `w_query()` 生成选项或摘要。
- 模板中禁止直接写库、写文件、发送外部请求、修改队列、触发业务状态变化。
- `w_query()` 在配置模板渲染中默认只允许只读 operation；写入类 operation 必须被 SystemConfig 拦截。
- 配置模板解析失败不能影响前台渲染；只影响后台配置中心对应模块的配置页。

模板不合法时：

- 开发环境抛出明确异常。
- 生产环境跳过该模板并记录错误日志。
- 后台配置中心显示模板错误摘要，避免静默丢配置。

### 保存边界

保存时只接受当前模板解析出的字段：

- 未由 `<w:config:field>` 声明的 key 拒绝保存。
- 当前 scope 级别未被字段 `scope` 允许时拒绝保存。
- `<w:config:adapter>` 拒绝普通保存。
- `env-lock` 命中的字段拒绝数据库覆盖。
- `value-type` 解码失败或 `validation` 失败时拒绝保存。

## Scope 读取

`SystemConfig` 依赖框架 `ScopeContext` 获取当前请求 scope。读取时如果调用方没有显式传入 scope，则使用当前请求 scope。

fallback 只按右侧层级逐步回退，避免不可预期命中：

```text
us.demo.vip
us.demo.default
us.default.default
default.default.default
default
```

`default.demo` 的兼容读取顺序：

```text
default.demo
default.default
default
```

新数据写入优先使用规范化三段 scope。短 scope 只作为兼容读取存在。

## 后台统一配置界面

后台提供一个统一的 SystemConfig 配置中心，行为类似 Magento 的 scope 配置，但 scope 由 Weline Framework 统一定义。

### 顶部配置上下文

配置中心顶部固定显示当前编辑上下文：

| 控件 | 说明 |
| --- | --- |
| Area | `backend`、`frontend`，默认按当前入口带入 |
| 配置级别 | 全局、站点、店铺 |
| 站点选择 | 选择站点级配置，数据来自 `Weline_Websites` |
| 店铺选择 | 选择店铺级配置，数据来自 `WeShop_Store`，必须先选站点 |
| Locale | 可选，仅展示字段 `locale` 启用的配置 |
| 模块搜索 | 搜索并锁定要配置的模块 |
| 配置搜索 | 在当前模块内搜索 group、label、key |

后台保存必须使用用户显式选择的 scope，不能使用后台请求自身的运行时 scope。这样后台管理员切换站点或店铺配置时，不会被当前后台域名、当前登录状态或预览状态污染。

### Scope 映射

| 配置级别 | 选择条件 | 写入 scope |
| --- | --- | --- |
| 全局 | 不选站点、不选店铺 | `default.default.default` |
| 站点 | 选择 website | `{website_code}.default.default` |
| 店铺 | 选择 website + store | `{website_code}.{store_code}.default` |

短 scope 例如 `default`、`default.demo` 只作为历史兼容显示和读取，不作为新后台写入格式。

### 继承交互

每个配置项显示：

- 当前有效值。
- 当前值来源：当前 scope、上级 scope、模块默认值、env locked。
- 当前 scope 是否已经覆盖。
- fallback 链。

每个可编辑配置项提供“使用继承值”开关：

- 开启继承：输入控件禁用，保存时删除当前 scope 的配置行。
- 关闭继承：输入控件启用，保存时写入当前 scope 覆盖值。
- 全局级别没有上级 scope，但可以“恢复模块默认值”，实现上同样删除全局配置行。

敏感配置：

- 后台只显示脱敏值。
- env locked 配置只读展示，不能关闭继承或保存覆盖。
- encrypted 配置允许重新填写，但不回显明文。

### 模块搜索与锁定

模块配置通过 Extends PHTML 配置模板进入配置中心。后台模块搜索由 SystemConfig 基于已解析模板索引完成，支持：

- 模块名，例如 `Weline_Theme`。
- 配置分组名。
- 配置 label。
- 配置 key。

选择模块后进入“模块锁定”状态：

- 左侧只显示当前模块的配置组。
- 保存请求只允许提交当前模块定义内的 key。
- 跨模块 adapter 只展示入口摘要，不混入当前模块普通表单。
- 模块自身不接管保存按钮、scope 下拉或搜索框；这些全局交互都属于 SystemConfig 配置中心。
- URL 应能表达锁定上下文，例如：

```text
system_config/backend/index?area=frontend&scope=us.demo.default&module=Weline_Theme&q=layout
```

### 页面布局

建议采用三栏或两栏结构：

```text
顶部：Area / Scope / Website / Store / Locale / Module Search / Save
左侧：模块列表 + 当前模块配置组
右侧：配置表单 + 来源说明 + 继承开关
```

配置项状态用明确标签展示：

| 标签 | 含义 |
| --- | --- |
| 当前覆盖 | 当前 scope 有显式值 |
| 继承 | 使用 fallback 或默认值 |
| Env 锁定 | env/secret 配置覆盖数据库 |
| 敏感 | 需要脱敏或加密 |
| Adapter | 复杂业务对象入口 |

### 站点与店铺数据来源

SystemConfig 后台不能直接依赖站点或店铺模块内部类。候选项通过 query provider 获取：

- `w_query('websites', 'getWebsiteList', ...)`
- `w_query('store', 'getStoreList', ['website_code' => ...])`

如果 `WeShop_Store` 未启用或没有店铺数据，店铺级配置入口隐藏，但站点级和全局配置仍可用。

### 保存语义

保存请求必须包含：

```text
area
module
scope
locale
values
inherit_keys
base_versions
reason
```

处理规则：

- `values` 内的 key 写入当前 scope。
- `inherit_keys` 内的 key 删除当前 scope 行。
- 未出现在当前模块定义里的 key 拒绝保存。
- adapter 类型配置不走普通 values 保存，跳转到对应模块管理入口。
- `base_versions` 必须匹配当前配置行版本；不匹配时拒绝保存并提示配置已被其他管理员修改。
- 保存必须在事务内完成，并写入配置版本批次。
- 保存成功返回 `version_id`，用于后台提示和快速回滚。

保存错误处理：

- 保存前校验全部字段白名单、scope 级别、value-type、validation、ACL 和 env-lock。
- 任何一项失败都不写入配置值，也不生成 `applied` 状态版本。
- 如果数据库层支持事务，使用事务包住值写入和版本写入。
- 如果当前存储暂时不支持事务，服务层必须做补偿恢复，并把该能力标记为过渡实现，后续补齐事务。
- 后台不能把“部分字段已保存”当成功展示；部分成功必须视为失败并给出恢复结果。

### 权限与审计

后台配置中心必须接入 ACL：

- `@config.acl` 可声明模块配置页权限，缺省使用模块级 SystemConfig 权限。
- 敏感字段需要额外的查看/修改权限；没有权限时只能看到脱敏状态，不能提交覆盖。
- `env-lock` 字段只读展示，不允许通过 ACL 绕过。
- `saveScopeConfig` 和 `rollbackScopeConfigVersion` 都必须记录 actor、scope、module、area、key 列表和结果。
- 审计日志不得记录敏感明文。
- 没有对应模块配置权限时，模块搜索可以返回模块名称，但不能进入配置表单或保存。

### 版本回滚

回滚用于处理“保存成功后发现配置错误”的立即恢复；真正的保存失败必须由事务和批次保护处理，不能先污染当前配置再靠回滚补救：

- 后台保存成功后展示最近一次 `version_id` 的回滚入口。
- 后台保存失败时不生成 `applied` 版本，不改变当前有效配置，并返回失败字段、失败原因和恢复状态。
- 回滚前再次检查当前 key 的 row version，只有未被后续保存修改的 key 才能自动回滚。
- 如果部分 key 已被后续修改，回滚必须给出冲突列表，由管理员选择跳过冲突 key 或取消回滚。
- 回滚继承删除操作时，恢复被删除前的配置行；回滚普通覆盖时恢复旧值或删除新增行。
- 回滚本身也生成新的版本批次，不能直接改历史记录状态而没有审计。
- 后台回滚结果必须展示成功 key、冲突 key、跳过 key 和失败原因。
- 回滚只针对同一个 module、area、scope、locale 批次，不跨模块回滚。
- 回滚完成后必须失效当前 scope 及 fallback resolve 缓存，避免界面仍显示旧值。

## API 设计

兼容旧 API：

```php
$systemConfig->getConfig($key, $module, $area);
$systemConfig->setConfig($key, $value, $module, $area);
```

新增 scope 能力：

```php
$systemConfig->getConfig(
    string $key,
    string $module,
    string $area,
    mixed $default = null,
    ?string $scope = null,
    ?string $locale = null
): mixed;

$systemConfig->setScopedConfig(
    string $key,
    mixed $value,
    string $module,
    string $area,
    string $scope,
    string $locale = ''
): bool;

$systemConfig->deleteScopedConfig(
    string $key,
    string $module,
    string $area,
    string $scope,
    string $locale = ''
): bool;

$systemConfig->saveScopeConfig(
    string $module,
    string $area,
    string $scope,
    array $values,
    array $inheritKeys = [],
    string $locale = '',
    array $baseVersions = [],
    string $reason = ''
): array;

$systemConfig->rollbackScopeConfigVersion(
    int|string $versionId,
    bool $skipConflicts = false
): array;
```

恢复继承必须删除当前 scope 的配置行，不能写空字符串、`null` 或 `false`。

需要补充可解释读取结果：

```php
$systemConfig->resolveConfig($key, $module, $area, $scope = null, $locale = null);
```

返回：

```php
[
    'value' => $value,
    'source' => 'exact_scope|fallback_scope|default|env_locked',
    'source_scope' => 'default.demo',
    'value_type' => 'json',
    'is_inherited' => true,
    'row_version' => 12,
]
```

## w_query 能力

`system_config` QueryProvider 扩展：

| operation | 说明 |
| --- | --- |
| `getConfig` | 读取有效配置 |
| `getConfigs` | 读取模块配置 map |
| `setConfig` | 兼容旧写入，默认写 `default` scope |
| `setScopedConfig` | 写当前 scope 覆盖 |
| `deleteScopedConfig` | 删除当前 scope 覆盖，恢复继承 |
| `resolveConfig` | 返回值和来源 |
| `getTree` | 返回后台配置树 |
| `getTemplates` | 返回 Extends 收集到的模块配置模板摘要 |
| `getTemplateMeta` | 返回指定配置模板解析后的 meta、group、field 白名单 |
| `getFallbacks` | 返回 scope fallback 链 |
| `getScopes` | 返回可选 scope 列表 |
| `getModules` | 返回可配置模块和分组摘要 |
| `getScopeOptions` | 返回全局、站点、店铺候选项 |
| `saveScopeConfig` | 按显式 scope 批量保存当前模块配置，返回 `version_id` |
| `rollbackScopeConfigVersion` | 按保存批次回滚配置，返回冲突和恢复结果 |
| `getConfigVersions` | 返回指定 module、area、scope、locale 的保存历史 |
| `getConfigVersionDetail` | 返回单个版本批次的变更摘要和可回滚状态 |

## 缓存与失效

所有缓存 key 必须包含：

```text
module + area + key + scope + locale
```

需要覆盖：

- single config cache
- module rows cache
- module map cache
- request cache
- fallback resolve cache

写入或删除某个 scope 配置时，必须失效：

- 当前 `module + area + key + scope + locale` 的 single cache。
- 当前模块和 area 的 rows/map cache。
- 依赖该 key 的 fallback resolve cache。

否则会出现站点、店铺、语言之间串配置。

## JSON Key 规则

`key` 是完整配置 key，不再隐式把 `a.b.c` 当 JSON path 拆开。配置值如果是 JSON，由 `value_type=json` 控制解码。

如果确实需要读取 JSON 子路径，后续通过独立参数或 helper 表达：

```php
$systemConfig->getJsonPath($key, $path, $module, $area, $scope = null);
```

核心 `getConfig()` 不再混用配置 key 和 JSON path，避免旧点号逻辑继续扩大。

## 安全规则

- 敏感配置必须使用 `encrypted`、`secret_ref` 或 env locked。
- 后台展示敏感配置时只显示脱敏值。
- env locked 配置在后台只读展示，不能被普通数据库配置覆盖。
- 不允许把密钥、token、cookie、私钥明文写入 `system_config.v`。
- 配置模板渲染中禁止执行写入类 operation。
- 配置保存和回滚必须经过 ACL 校验。
- 配置保存历史和审计日志不得记录敏感明文。

## 复杂业务配置接入

统一配置系统不强制所有业务对象物理存储到 `system_config`。

保留业务表的场景：

- Payment：支付方式、环境、凭据测试、运行时覆盖。
- Search：搜索引擎 profile、连接参数、优先级。
- Theme layout asset：虚拟布局源码、版本、发布状态、AI 草稿。

这些模块需要接入统一 Scope：

- 使用框架 `ScopeContext` 和 fallback resolver。
- 在后台配置树中以 adapter 暴露摘要或入口。
- 不把复杂对象强行压成普通 key/value。

## 验收项

- 旧 `SystemConfig` 调用保持可用。
- 老数据迁移到 `scope=default` 后读取一致。
- 当前 scope 命中优先。
- 当前 scope 缺失时按 fallback 链读取。
- 恢复继承通过删除当前 scope 行生效。
- 后台可以选择全局、站点、店铺 scope 并保存对应覆盖。
- 后台模块搜索可以锁定模块和模块内配置组。
- 后台保存不会使用后台请求的隐式 runtime scope。
- 缓存不跨 scope、locale 串值。
- `w_query('system_config', ...)` 能返回配置值、配置树、模板 meta 和来源。
- 敏感配置不明文回显。
- 每次后台保存生成版本批次，返回 `version_id`。
- 保存失败不会留下半批次配置。
- 最近保存批次可以立即回滚。
- 回滚遇到后续修改能识别冲突并阻止误覆盖。
- ACL 不足时不能进入模块配置表单、保存敏感配置或执行回滚。
