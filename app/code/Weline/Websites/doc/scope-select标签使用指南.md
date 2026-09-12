# `<w:scope>` 作用范围选择标签（四级）

> **写后台「作用范围 / 售卖范围 / 刊登范围」前必读。**  
> 身份模型归 `Weline_Websites`；选择器标签实现在 `Weline_Taglib`；树数据经 `SystemConfig` 的 `ScopeSelectorCatalog` 组装。禁止在业务页拆成手写网站 + 店铺 + 渠道三套控件冒充范围。

权威模型：`store-saleschannel-scope.md`（Website → Store → Channel 三段 + Global）。  
标签源码：`app/code/Weline/Taglib/Taglib/Scope.php`。  
场景映射：`Taglib/doc/场景映射表.md`「作用范围（四级）」。

---

## 1. 四级是什么

| 层级 | `kind` | 典型 `target_scope` / storage | 说明 |
|------|--------|-------------------------------|------|
| Global | `global` | `default.default.default` | 全部网站/店铺/渠道；**配置继承根**，多数业务写路径禁止当「具体目标」 |
| 网站 | `website` | `default.__website__.default` | 选到某一 Website（含 `website_id=0` 默认站） |
| 店铺 | `store` | `default.{store_code}.default` | 选到某一 Store |
| 渠道 | `channel` | `default.{store_code}.{channel_code}` | 选到某一 SalesChannel |

树顺序：**Global → Website → Store → Channel**。子层继承父层配置，直至被覆盖（见 SystemConfig 继承文档与 MCP `weline_business_scope_hierarchy`）。

---

## 2. 何时用 `<w:scope>`，何时用单域 Taglib

| 场景 | 用什么 | 禁止 |
|------|--------|------|
| 配置中心 / 货源配置 / 仓映射 / 刊登范围 / 任意「作用范围」工具条 | **`<w:scope>`** | 拆开 `<w:websites:website:select>` + `store:select` + `channel:select` 手拼联动 |
| 仅筛站点（如列表过滤、多站勾选） | `<w:websites:website:select>` | 把站点下拉当完整 Scope |
| 已知 `website_id` 后只选店铺 ID（如库存授权） | `<w:websites:store:select>` + `StoreSelectOptions` | 手填 `store_id` |
| 已知 Store 后只选渠道 code | `<w:websites:channel:select>` | 裸 `<select>` 拼渠道 |

**规则：** 只要 UI 文案是「范围 / Scope / 作用范围 / 售卖范围 / 刊登范围」，一律 `<w:scope>`。单域 Taglib 只服务「只要一层」的过滤或表单字段。

---

## 3. 模块职责（解耦）

| 模块 | 职责 |
|------|------|
| **Weline_Websites** | Website / Store / SalesChannel 主数据与 Catalog；经 `ScopeIdentityCatalog` 向选择器贡献站/店/渠节点 |
| **Weline_SystemConfig** | `ScopeSelectorCatalog` 组装 Global + 树；`SystemConfigTargetScopeService` 解析表单 `target_scope` |
| **Weline_Taglib** | `<w:scope>` 渲染（树形可搜索选择器；兼容旧 `container-id` 持久化用法） |
| **业务壳（如 Dropship）** | 模板只贴标签；POST 只交 `target_scope`（或分段 code），再调 TargetScopeService 得到 `website_id` / `store_id` / `channel` |

Websites **不**在本模块再实现第二套「范围 Taglib」；身份与 Catalog 已是范围树的数据所有权。跨模块禁止绑 Store/SalesChannel Model，只读走 `StoreCatalogInterface` / `SalesChannelCatalogInterface`。

---

## 4. 模板用法

```html
<label class="w-field__label" for="demo-scope"><lang>作用范围</lang></label>
<div class="w-field__control">
    <w:scope
        id="demo-scope"
        name="target_scope"
        value="selected_scope"
        placeholder="@lang(选择网站、店铺或渠道)"
        search-placeholder="@lang(搜索作用范围)"
        aria-label="@lang(选择作用范围)"
        searchable="true"
        default-expand-all="true"
        required="true"
    />
</div>
```

Controller 侧至少：

```php
$this->assign('selected_scope', 'default.__website__.default'); // 或 resolve 后的 storage_scope
```

### 常用属性

| 属性 | 说明 |
|------|------|
| `id` / `name` | 控件 id；表单字段名建议 `target_scope` |
| `value` | 模板变量名（如 `selected_scope`），值为 storage scope 字符串 |
| `searchable` | 默认 true，可搜索树节点 |
| `default-expand-all` | 默认 true，展开整树 |
| `required` | 默认 true |
| `options` | 可选；不传则运行时调 `ScopeSelectorCatalogInterface::build()` |
| `form` | 关联外置 form id（与其它 Taglib 一致） |

### 兼容旧用法（持久化 / 非选择器）

仅当带 `container-id`（或仅 `url`/`event` 且无 `name`/`value`）时走持久化 span，**不是**四级选择器：

```html
<w:scope url="@backend-url('taglib/backend/scope')" container-id="page-root" event="input change click"/>
```

后台配置/业务范围选择**不要**用这套旧属性冒充范围选择器。

---

## 5. 提交与解析

表单提交 `target_scope`（推荐），或分段 `website_code` / `store_code` / `channel_code`。

```php
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;
use Weline\Framework\Runtime\ScopeIdentity;

/** @var SystemConfigTargetScopeService $svc */
$resolved = $svc->resolveFromInput([
    'target_scope' => (string)$this->request->getPost('target_scope', ''),
], false);

$storageScope = (string)$resolved['storage_scope'];
/** @var ScopeIdentity $identity */
$identity = $resolved['identity'];
$claims = $identity->toArray();
$websiteId = $claims['website_id']; // Global 时为 null；默认站可为 0
```

注意：

- **禁止**把「请求里存在的空 `website_code`」与 `target_scope` 混交：空分段键会被当成 Global，盖掉深链（仓映射已踩坑，见 Dropship 开发日志）。
- 业务若要求「必须落到具体网站」，在 `website_id === null` 时 fail-closed 并提示改选网站层及以上。
- 网站级映射常存 `store_id=0`；仅 store/channel 层再解析具体店铺 ID（对齐仓映射）。

参考实现：

- `Dropship/view/templates/Backend/Warehouse/index.phtml`、`Config/index.phtml`
- `Dropship/view/templates/Backend/Listing/index.phtml`（刊登弹窗）
- `Dropship/Controller/Backend/Warehouse.php`、`Listing.php`（`resolveFromInput`）

---

## 6. 验收清单

- [ ] 控件是单个树选，不是并排网站/店铺/渠道三个下拉
- [ ] 树可见 Global / 网站 / 店铺 / 渠道节点（ACL 过滤后可少，但模型仍是四级）
- [ ] 提交字段为 `target_scope`（或文档约定分段），服务端经 `SystemConfigTargetScopeService`
- [ ] 未手写站店渠 `<select>`，未用路径通配 JSON 冒充 Scope
- [ ] 文档与模板文案写「作用范围」，路径过滤另称「路径过滤」

---

## 7. 相关文档

| 文档 | 用途 |
|------|------|
| [store-saleschannel-scope.md](./store-saleschannel-scope.md) | Scope 三段模型、解析、Token |
| [README.md](./README.md) | 模块总览与 Taglib 索引 |
| [Taglib 场景映射表](../Taglib/doc/场景映射表.md) | 全局「何时用哪个标签」 |
| SystemConfig README 继承章节 | channel←store←website←global 回退 |
DOC
