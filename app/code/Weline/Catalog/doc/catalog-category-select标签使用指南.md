# `<w:catalog:category:select>` 分类选择标签使用指南

> Catalog 官方分类多选控件。商品创建/编辑等后台表单需要「选分类」时优先用本标签，禁止手写 checkbox 列表。  
> 权威实现：`Taglib/CategorySelect.php`。契约测试：`Test/Unit/Taglib/CategorySelectContractTest.php`。

## 1. 一句话

树形多选分类：顶部芯片展示已选、下拉内搜索与勾选、选中叶子时自动勾选整条祖先链；可选底部快速新建；可与 `<w:scope>` 草稿持久化联动回填。

## 2. 最小示例

```html
<?php
$catalogCategoryOptionsJson = json_encode($options, JSON_UNESCAPED_UNICODE);
$catalogCategoryValue = '12,34'; // 回填：逗号分隔 category_id
$catalogCategoryEmpty = __('未选择分类');
$catalogCategoryPlaceholder = __('搜索分类名称或路径');
$catalogCategoryWebsiteId = 0;
?>
<w:catalog:category:select
    id="product-create-categories"
    name="category_ids"
    value="catalogCategoryValue"
    options="catalogCategoryOptionsJson"
    empty-label="catalogCategoryEmpty"
    placeholder="catalogCategoryPlaceholder"
    website-id="catalogCategoryWebsiteId"
    space="product"
    allow-create="true"
    scope="product_create_draft"
/>
```

消费端必须通过标签 JS API 回填/选中（不要只写 hidden 期望芯片变名字）：

```js
var api = window.WelineCatalogCategorySelect['product-create-categories'];
api.getValue();   // "138,136,135"（含祖先链时）
api.getValues();  // ["138","136","135"]
// 按 options 内部勾选 + 芯片显示名称；不在树中的陈旧 ID 会被丢弃（避免 #id）
api.setValue('138', { silent: true });
api.select('138,143', { silent: true }); // select 与 setValue 同义
```

> 回填 ID 必须存在于当前 `options`（或标签拉到的 `catalog/tree`）。历史草稿里的过期 ID 会被过滤，芯片不会再显示 `#3` 这类占位。

## 3. 属性

| 属性 | 必填 | 说明 |
|------|------|------|
| `id` | 是 | 控件 ID；同时作为 `WelineCatalogCategorySelect[id]` 键 |
| `name` | 否 | 隐藏域 name，默认 `category_ids` |
| `value` | 否 | 初始值：逗号分隔 category_id（变量名或字面量，走 AttributeCodeCompiler） |
| `options` | 否 | JSON 数组或 PHP 数组变量；缺省且给定 `website-id` 时走 `catalog/tree` |
| `website-id` | 否 | Website 范围；快速新建与缺省拉树需要 |
| `space` | 否 | Catalog 空间，默认 `product` |
| `allow-create` | 否 | 是否显示「快速新建分类」，默认 `true` |
| `scope` | 否 | 写到隐藏域上，供 `<w:scope container-id="...">` 草稿持久化 |
| `placeholder` | 否 | 下拉搜索框占位 |
| `empty-label` | 否 | 未选时芯片区文案 |
| `form` / `class` / `style` / `on-change` | 否 | 标准表单扩展 |

### options 项字段

| 字段 | 说明 |
|------|------|
| `value` / `category_id` | 分类 ID |
| `label` / `name` | 显示名 |
| `meta` / `path` | 副标题路径，如 `/books/fiction` |
| `parent_id` / `pid` | 父分类 ID（`0` 为顶级） |

## 4. 交互约定

1. **多选**：勾选写入隐藏域（逗号分隔 ID），芯片可单颗移除。
2. **选叶子勾祖先**：勾选节点时 `selectWithAncestors` 自动勾选父链并展开；取消仅去掉当前节点。
3. **滚动**：面板内 `wheel`/`scroll` 不关闭下拉；外部滚动只重定位（对齐 DomainSelect）。
4. **快速新建**：调用 `Weline.Api.resource('catalog_category_admin').categoryAdminSave(...)`；需对象 ACL create 授权，否则会业务拒绝（树形多选仍可用）。
5. **暗色主题**：选中/悬停使用 `color-mix` 与卡片背景，避免浅色块。

## 5. 与 `<w:scope>` 自动保存 / 回填

商品创建页示例：

```html
<form id="product-create-form">
  ...
  <w:catalog:category:select
      id="product-create-categories"
      name="category_ids"
      scope="product_create_draft"
      ...
  />
</form>
<w:scope
    url="@backend-url('taglib/backend/scope')"
    container-id="product-create-form"
    event="change"
/>
```

要点：

- 用户勾选分类会 `change` 隐藏域 → scope-persistence 写入 `category_ids`。
- 回填时 Theme UI 组件 `weline-scope-persistence`（源：`Weline_Taglib/view/statics/js/scope-persistence.js`，经 `static:compile welineUi` 产出）识别 `data-catalog-category-select-value`，调用 `setValue(..., { silent: true })`，避免空 `change` 覆盖远端草稿。
- 控件就绪会派发 `weline:catalog-category-select-ready`；若 scope 先到会进 pending，就绪后再补填。
- **商品创建页双保险**：`product-admin.js` 的 `forceHydrateCategoriesFromDraft` 在向导 hydrate（0/400/1200ms）调用 `setValue(..., {silent:true})` 让标签内部勾选；若返回值因陈旧 ID 被过滤，会 `patchProductCreateDraftLocal` 纠正草稿。
- 弹出层列表高度对齐 DomainSelect：`ensureListScrollable` + FloatingDropdown `scrollContainer`，避免「快速新建」挤矮树列表。
- 修改 Taglib 源后必须：`php bin/w static:compile welineUi && php bin/w deploy:upgrade`（scope 源）或对本标签 PHP 热更后 `server:reload -r`。

## 6. JS API

`window.WelineCatalogCategorySelect[id]`：

| 方法 | 说明 |
|------|------|
| `getValue()` | 当前选中 ID 的逗号分隔串（与芯片/`selected` 一致） |
| `getValues()` | ID 数组副本 |
| `setValue(v, opts?)` / `select(v, opts?)` | **按 options 内部勾选**（默认 `withAncestors`），重绘芯片与树；`silent` 不派发 change；默认丢弃不在 options 中的 ID（避免 `#id` 芯片） |
| `setOptions(rows)` | 替换 options 后按当前选中重匹配并重绘 |

## 7. 禁止手写

| 禁止 | 原因 |
|------|------|
| 分类 checkbox / 扁平多选列表 | 无树形、无祖先联动、无官方快速新建 |
| 自写下拉 + fetch 分类树 | 与 Catalog Hub / ACL / scope 回填脱节 |

## 8. 相关路径

| 路径 | 作用 |
|------|------|
| `app/code/Weline/Catalog/Taglib/CategorySelect.php` | 标签实现 |
| `app/code/Weline/Taglib/view/statics/js/scope-persistence.js` | scope 草稿源（含分类静默回填） |
| `app/code/Weline/Theme/view/statics/ui/components/weline-scope-persistence.js` | 编译后的 UI 组件（页面实际加载） |
| `app/code/Weline/Product/view/templates/backend/catalog/index.phtml` | 商品创建页消费示例 |
| `app/code/Weline/Product/view/statics/js/backend/product-admin.js` | `forceHydrateCategoriesFromDraft` 草稿/芯片双保险回填 |
| `app/code/Weline/Taglib/doc/场景映射表.md` | 官方控件选型入口 |
