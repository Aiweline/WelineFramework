# `<w:config:embed>` 配置嵌入标签使用指南

> 消费侧真实 Taglib：在任意后台模板嵌入**已在 Extends 配置模板中声明**的字段/分组/模块配置。  
> 权威实现：`Taglib/ConfigEmbed.php`、`Service/ConfigEmbedResolver.php`、`Service/ConfigEmbedRenderer.php`。  
> 需求：`REQ-SYSTEMCONFIG-0004`（见 [需求.md](./需求.md)）。

## 1. 一句话

把「是否启用」这类配置从统一配置中心整页，挪到业务页就地展示与即时保存——**不重新声明字段**，只消费已有契约。

## 2. 和声明类标签的区别

| 标签 | 性质 | 放哪里 | 作用 |
|------|------|--------|------|
| `<w:config:group>` / `<w:config:field>` / `<w:config:adapter>` / `<w:config:hint>` | **声明契约**（模板解析，不是真实 Taglib） | `extends/module/Weline_SystemConfig/Config/{area}/{code}.phtml` | 定义 key、type、scope、敏感等 |
| `<w:config:embed>` | **真实 Taglib（消费侧）** | 任意后台 `.phtml` | 按声明渲染控件并即时写入 |

禁止在 embed 里「临时发明」新 key；未声明 key 会红标、不可改，但不阻断同标签其他字段。

## 3. 最小示例

```html
<!-- 单字段（默认竖排） -->
<w:config:embed
    module="Weline_Payment"
    field="payment/method/paypal/enabled"
/>

<!-- 表格单元格：只显示控件，隐藏 label/key -->
<w:config:embed
    module="Weline_Payment"
    field="payment/method/paypal/enabled"
    layout="inline"
/>

<!-- 多字段 -->
<w:config:embed
    module="Weline_Payment"
    fields="payment/method/paypal/enabled,payment/method/paypal/sort_order"
    layout="horizontal"
/>

<!-- 整个分组（含同 group 的 hint / adapter 摘要） -->
<w:config:embed module="Weline_Payment" group="paypal_runtime" />

<!-- 模块某 area 下全部已声明字段 -->
<w:config:embed module="Weline_Payment" area="backend" />
```

## 4. 属性一览

| 属性 | 必填 | 默认 | 说明 |
|------|------|------|------|
| `module` | **是** | — | 配置所属模块名，如 `Weline_Payment`。缺省 → 整块错误提示。 |
| `area` | 否 | `backend` | 配置模板区域，与 Extends 路径 `{area}` 一致。 |
| `field` | 否* | — | 单个配置 key。 |
| `fields` | 否* | — | 多个 key，分隔符：`,` `;` 空白。 |
| `group` | 否* | — | 按字段声明的 `group` 选字段；同组 `hint`/`adapter` 一并展示（只读摘要）。 |
| `layout` | 否 | `vertical` | `vertical` \| `horizontal` \| `inline`。非法值回落 `vertical`。 |
| `template` | 否 | 默认壳 | 覆盖整段外壳 phtml（见 §8）。 |
| `locale` | 否 | `default` | 读写 locale。**省略或空 → `default`**，避免落入后台 UI 语言行导致「保存成功刷新丢值」。 |
| `class` | 否 | — | Taglib 已接收该属性，**当前默认壳尚未应用到根节点 class**（预留；需要样式请用 `template` 或外层包一层）。 |

\*选择优先级见下一节：`field`/`fields` 优先于 `group`，再优先于「整模块 area」。

## 5. 选择优先级（Resolver）

1. 若 `field` 或 `fields` 非空 → **只按列出的 key** 渲染（不去校验 key 是否声明；未声明进红标项）。
2. 否则若 `group` 非空 → 收集该 `module`+`area` 下 `group` 匹配的字段，并附加同组 hint/adapter；若全空 → 块级错误「分组不存在或为空」。
3. 否则 → 该 `module`+`area` 下**全部已声明字段**；若无字段 → 块级错误。

同一次标签内：未声明字段与合法字段可并存；未声明不可编辑，不阻断同级保存。

## 6. Scope（只信 URL）

- 从当前请求 GET 解析：`target_scope`，或 `scope`，以及可选 `website_code` / `store_code` / `channel_code`。
- 走 `SystemConfigTargetScopeService::resolveFromInput(..., allowSessionFallback: false)`。
- **禁止 Session / 页面类型推断**。
- URL 无范围 → **Global**（`default.default.default`）。
- 字段声明的 `scope=`（如 `global,website,store`）会再限制：当前 URL 范围不在允许列表 → `scope_denied`，控件禁用并 tip。

业务页若需要非 Global：页面自身必须把 Scope 放进地址栏（与配置中心一致），embed 才会写到正确范围。

## 7. 保存与前端行为

- 资源：`view/statics/js/config-embed.js`（壳层首次渲染时注入；`defer`）。
- 控件带 `data-w-config-embed-control`：
  - `checkbox` / `select` → `change` 立即保存；
  - 文本类 → `input` 防抖 300ms + `blur` 再保存。
- 写路径：`Weline.Api` → `api.resource('system_config').setScopedConfig({...})`。
- 载荷关键字段：`key`、`value`、`module`、`area`、`locale`（与壳 `data-locale` 一致）、`target_scope`、`expected_grant_version`、`reason=config_embed_immediate_save`；有则带 `value_type`、`website_code`/`store_code`/`channel_code`。
- 成功/失败：`Weline.UI.toast`；失败回滚控件到上次值。
- 根节点 `data-can-update≠1` 时 JS 直接不写。

不要在业务页再手写一套 `setScopedConfig` 开关逻辑（Payment 列表已删掉页面内写路径，统一走 embed）。

## 8. 布局与自定义模板

### layout

| 值 | 效果 |
|----|------|
| `vertical`（默认） | 字段纵向列表；显示 label、key、说明 |
| `horizontal` | 字段横排换行 |
| `inline` | **隐藏** `.w-config-embed__field-meta`（label/key/说明），适合表格「是否启用」列只露开关 |

### template（外壳覆盖）

- 空：默认 `view/templates/taglib/config-embed.phtml`。
- `Vendor_Module::相对路径`：解析为 `app/code/Vendor/Module/view/{相对路径}`。
- 纯相对路径：先试 SystemConfig `view/`，再试仓库根相对路径。
- 禁止路径含 `..`。
- 找不到文件 → 错误壳提示「自定义模板不存在」。
- **字段级控件**仍由默认 `config-embed-field.phtml` 按 `type` 渲染；`template` 只换整段外壳/排布。

自定义壳可使用的变量：`$embed`（Resolver 视图模型）、`$embedFieldPartial`（字段 partial 绝对路径）、`$embedCssUrl` / `$embedJsUrl`。

## 9. ACL / 敏感 / 未声明

| 状态 `data-status` | 含义 | UI |
|--------------------|------|-----|
| `ok` | 可编辑（有 UPDATE 且非敏感且 scope 允许） | 正常控件 |
| `forbidden` | 无对象 Scope **UPDATE** | 灰显 + deny tip（需 UPDATE；当前范围） |
| `scope_denied` | 字段 scope 不允许当前 URL 范围 | 灰显 + tip |
| `sensitive_readonly` | password/secret 或 `is_sensitive` | 只读掩码 + 「前往统一配置中心修改」深链 |
| `undeclared` | key 未在配置模板声明 | 红标「没有这个字段」，禁用输入 |

无 **VIEW**：整块 `ok=false`，不列字段。  
`expected_grant_version` 写入根节点，保存时带回，与配置中心授权版本对齐。

深链查询含：`module`、`area`、`guide_key`、`guide_locate`、`target_scope` 及分段 code。

## 10. 字段 type → 控件（当前实现）

| `type`（小写） | embed 控件 |
|----------------|------------|
| `switch` / `checkbox` / `boolean` | `w-switch` + checkbox |
| `select`（且有 options） | `<select class="w-select">` |
| `textarea` | textarea |
| `number` | `input type=number` |
| `password` / `secret` | 禁用 password 掩码（只读） |
| 其他（含 `text`、以及尚未单独映射的 `radio`/`multiselect`/`image`/`file`/`json`/`code`/`color`/`date` 等） | 普通 text input（或敏感则掩码） |

`value_type`：优先用字段对象的 `value_type`；否则由 type 推断（switch→`bool`，number→`int`，其余→`string`），写入 `data-value-type` 供保存。

与配置中心整页相比：embed **未**接入媒体库选图、多选、JSON 编辑器等完整控件；复杂字段请深链配置中心，或后续扩展 field partial。

## 11. 属性绑定方式（编译 vs 运行时）

### 11.1 变量名绑定（推荐列表循环）

Taglib 属性值写成**无 `$` 的 PHP 变量名**时，编译期解析为该变量：

```php
<?php
$embedModule = $providerModule; // 如 Weline_Payment
$embedField = 'payment/method/' . $methodCode . '/enabled';
?>
<w:config:embed
    module="embedModule"
    field="embedField"
    area="backend"
    layout="inline"
/>
```

真实样例：`Weline_Payment/view/templates/Backend/Method/index.phtml`。

### 11.2 静态字面量

```html
<w:config:embed module="Weline_Payment" field="payment/method/paypal/enabled" />
```

### 11.3 动态属性 / `<?= ... ?>`（必须走 runtime）

若属性值含 PHP 表达式插值，框架走 `renderRuntimeTag` → **`ConfigEmbed::runtimeCallback`**，直接返回 HTML。

不要假设 `callback()` 吐出的 `<?php ... ?>` 会在动态属性路径再执行——早期 bug 会把 PHP 源码当 HTML 显示；现已用 `runtimeCallback` 修复。

## 12. 数据面与资源

| 路径 | 职责 |
|------|------|
| `Taglib/ConfigEmbed.php` | 标签名 `config:embed`、属性、callback / runtimeCallback |
| `Service/ConfigEmbedResolver.php` | Scope、ACL、选择、字段 DTO |
| `Service/ConfigEmbedRenderer.php` | 解析模板、注入 CSS/JS、include 壳 |
| `view/templates/taglib/config-embed.phtml` | 默认外壳 |
| `view/templates/taglib/config-embed-field.phtml` | 单字段控件 |
| `view/statics/js/config-embed.js` | 即时保存 |
| `view/statics/css/config-embed.css` | 布局与主题 token（无写死浅色底） |

写入口复用 Query：`system_config.setScopedConfig`（前端已暴露，ACL `config_center_save`）。

## 13. 非目标

- 不替代统一配置中心整页（版本、批量、adapter 一键授权、媒体库等仍以中心为准）。
- 不在 embed 内声明新字段（那是 Extends 配置模板的事）。
- 不靠 Session 或「我在店铺详情页」推断 Scope。
- 不在业务页重复实现开关写路径。

## 14. 排错清单

| 现象 | 排查 |
|------|------|
| 页面出现 `<?php` / `Taglib__` 源码 | 动态属性未走 runtime；确认 Taglib 已收集且模块版本 ≥ 含 `runtimeCallback` 的构建；执行 `taglib:collect` / `setup:upgrade --route`。 |
| 保存 toast 成功，刷新恢复旧值 | locale 错位：确认壳 `data-locale=default` 且 JS 写入带 `locale`；模块 ≥ 1.3.1。 |
| FrontendQueryGateway：`Unknown frontend worker param: value_type` | 模块 ≥ 1.3.2，`commonWriteParams` 已声明 `value_type`。 |
| 一直灰显 | 检查对象 Scope UPDATE；或字段 `scope=` 不含当前 URL 层；或敏感只读。 |
| 红标「没有这个字段」 | Extends 配置模板未声明该 key，或 `module`/`area` 写错。 |
| 写到 Global 而非当前店铺 | 业务页 URL 缺少 `target_scope`（或分段 code）。 |
| 表格里还显示 label/key | 加 `layout="inline"`。 |
| 索引里找不到标签 / 摘要仍是旧文案 | 先全量 `php bin/w taglib:collect`（不要只用 `--module`，否则不重写注册表 `doc`），再 `php bin/w taglib:catalog-generate`；场景见 [Taglib 场景映射表](../../Taglib/doc/场景映射表.md)。 |

## 15. 验收建议

1. 后台打开已嵌入页（如支付方式列表），URL 带目标 `target_scope`。
2. 有 UPDATE 时切换开关 → toast 成功 → 刷新仍保持。
3. 去掉 UPDATE（或换无权限账号）→ 灰显，不可写。
4. 故意写未声明 `field` → 红标，同页其他 embed 仍可用。
5. 敏感字段 → 只读 + 深链可打开配置中心定位。

自动化：`Test/Unit/Taglib/ConfigEmbedContractTest.php`、`Test/Unit/Service/ConfigEmbedResolverTest.php`、Payment `MethodIndexTemplateHeaderContractTest`（断言列表使用 embed、无手写 `setScopedConfig`）。

## 16. 相关文档

- [README.md — 配置嵌入摘要](./README.md)
- [scope-config-tree-plan.md — 标签表](./scope-config-tree-plan.md)
- [需求.md — REQ-SYSTEMCONFIG-0004](./需求.md)
- [Taglib 场景映射表](../../Taglib/doc/场景映射表.md)
- [标签全量索引](../../Taglib/doc/标签全量索引.md)（先全量 `php bin/w taglib:collect`，再 `php bin/w taglib:catalog-generate`；仅 `--module` 收集不会刷新注册表 `doc`）
