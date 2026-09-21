# MediaReferenceIdentity.v1

权威协议：选图 / 上传 / AI 加图的**唯一**身份构造入口是框架助手 **`w_scope`**。禁止手拼 `identity_path`。

## w_scope

```php
w_scope(?string $scope, string $type, string $code, array $other = []): MediaReferenceIdentity
```

| 参数 | 含义 | 强制 |
| --- | --- | --- |
| scope | `storage_scope`（三点分） | 否：Web/Ambient 有上下文可省略；**CLI 必传** |
| type | 域：`product` / `widget` / `theme` / … | 是 |
| code | 身份码（sku / theme_code…） | 是 |
| other | slot：`kind`/`field`/`component`/`locale`/`instance`/`code_key`… | 否 |

前端同构：`window.w_scope`（Theme.js / FileManager `w-scope.js` 挂载）。

## 段型

- `scope:` **只表示范围**（storage_scope）
- `sku:` / `theme:` / `brand:` … **只表示身份**
- 定位卸引用：`type` AND `scope` AND `code`（不合成 `scope~sku`）

示例：

```text
product:sku:HF-HANFU-188:scope:default.default.default:kind:media:role:main
```

## 场景 ID（优先套用）

| ID | path 要点 |
| --- | --- |
| sc.product.media | `product:sku:{sku}:scope:{ss}:kind:media:role:…` |
| sc.product.detail | `…:kind:detail` |
| sc.product.variant | `…:kind:variant:…` |
| sc.widget.field | `widget:theme:{tc}:scope:{ss}:…:component:{w}:field:{f}[:instance:]` |
| sc.theme.brand | `theme:theme:{tc}:scope:{ss}:field:…` |
| sc.catalog.category | `catalog:category:{path}:scope:{ss}:field:…` |
| sc.blog.cover | `blog:post:{slug}:scope:{ss}:field:cover` |
| sc.config.media | `config:key:{key}:scope:{ss}:ns:…` |
| sc.backend.profile_avatar | `config:key:backend_profile_avatar/{username}:scope:{ss}:kind:media:field:avatar:component:backend:ns:backend`（个人中心头像；PHP `ConfigMediaReferenceTemplates::config` + 页首 `w-scope.js`） |
| sc.smtp.bg | `smtp:mail:{code}:scope:{ss}:…` |
| sc.eav.swatch | `eav:attribute:{attr_code}:scope:{ss}:kind:swatch:field:{option_code}` |
| sc.product_brand.logo | `product_brand:brand:{code}:scope:{ss}:kind:logo:field:logo` |
| sc.product_supplier.image | `product_supplier:supplier:{code}:scope:{ss}:kind:image:field:image` |

### 前端强引用开选

`file-picker.js`：`strong_ref` 时若无显式 `data-w-identity`，会用 `identity_*` + `window.w_scope` 自建；`w_scope` 未挂载 → toast「强引用选图缺少媒体身份」。业务页须 **页首同步挂 `w-scope.js`**，并**优先 PHP 预计算 `identity` path**（见 [file-manager-选图与file-image出图.md](file-manager-选图与file-image出图.md) 强引用清单）。

## 生命周期

1. 选图确认 → 建引用（标签含 scope + 身份键）；换图 **只卸引用、不删文件**
2. 实体删除 → `w_changed`（`resource.code` 必填；`resource.scope` **可选**）→ FM Observer 按 scope+code 卸引用
3. 物理删 → 垃圾箱（FileAsset `deleted_at`）；永久删须零引用

## 前端分工

- **通用**：`<file-manager>` 调 `w_scope` 自建身份
- **复杂（可视化编辑器）**：壳给 Tag **显式设身份**（含 instance）
- 优先级：Tag 显式身份 > Ambient + `w_scope` 自建 > fail-closed（强引用）

## resource.scope

`ResourceChange.resource.scope` 为 **optional** 三点分码；与 `resource.code` 分字段。非强制：请求上下文能解析时可不传；CLI 必传。

## 用例（EARS 摘要）

- UC-1.x：规格 / skill / MCP `media_reference_identity_protocol` 可加载。
- UC-2.1：Ambient 完整才产出 path；缺维不静默成功。
- UC-2.2：强引用缺身份拒绝开选；完整则 URL 含 identity。
- UC-2.2b/c/d：确认选图即入账；single/multi 只换引用账、不删文件；multi 用 session/POST 的 `asset_id[]`。
- UC-2.3：业务保存 reconcile 与表单一致。
- UC-2.4/2.6：共享图只卸本范围引用；`delete_references_by_scope` 保留他范围共享文件。
- UC-3.x：Theme Ambient + widget instance + 双语 locale 各一条。
- UC-4.x：引用管理模式删只卸引用；无引用可清本体；关站调 scope API。
- UC-4.7 / UC-5–7：详情 AI `kind:detail`；商品/分类/博客/配置/Smtp/AI 另存均走 `w_scope`。

负面：裸 usage 开选拒绝；禁止数字主键进 path；禁止 GET 超长已选地址列表；换图不进物理删。
