# Theme Metadata Reference

## 1. Layout / Partial / Component / Variable / Color 注释

这些文件优先使用 `@meta.*` 和 `@param.*`。

### 最小布局示例

```php
<?php
/**
 * @meta.name {default="首页默认布局",name="首页默认布局"}
 * @meta.description {default="首页布局",name="首页布局描述"}
 * @param.title {default="首页",name="页面标题"}
 * @param.showHeader {default=true,name="是否显示头部"}
 */
```

### 最小 CSS 元数据示例

```css
/**
 * @meta.name {default="颜色变量",name="颜色变量"}
 * @meta.description {default="主题颜色 token",name="主题颜色 token"}
 */
```

规则：

- `@meta.name` 必填
- `@meta.description` 必填
- 有运行时参数就写 `@param.xxx`
- `@param` 的默认值要与模板真实行为一致

## 2. Widget 注释

widget 使用 `@widget.*` + `@param.*`。

### 最小 widget 示例

```php
<?php
/**
 * @widget.code {logo}
 * @widget.name {Header Logo}
 * @widget.description {网站 Logo}
 * @widget.type {header}
 * @widget.area {frontend}
 * @widget.position {["header"]}
 * @widget.page_layouts {["*"]}
 *
 * @param logo_text {default="WeShop",type="string",label="Logo文字"}
 */
```

至少保证：

- `@widget.code`
- `@widget.name`
- `@widget.description`
- `@widget.type`
- `@widget.area`

与目录一致：

- 目录 `widgets/header/logo/default.phtml`
- `@widget.type {header}`
- `@widget.code {logo}`

## 3. 模板里可直接使用的数据

### layout 模板常见数据

- `meta`
- `theme`
- `colors`
- `contentTemplate`

### partial 模板常见数据

- `meta`
- `layout`
- `theme`
- `colors`
- `contentTemplate`

说明：

- `meta` 来自当前文件自己的 `@param.*`
- `layout` 是当前布局的 `meta`
- `theme` 含 `area`、`colorMode`、`layoutType`、`layoutOption`、`theme` 对象
- `colors` 是当前主题变量整理后的键值对

## 4. 标签优先级

`.phtml` 中优先使用：

- `{{ ... }}`
- `@lang{...}` / `@lang(...)`
- `@static(...)`
- `@url(...)`
- `@backend-url(...)`
- `@var(...)`
- `<theme:css>...</theme:css>`
- `<theme:js>...</theme:js>`

### 主题资源示例

```html
<theme:css>Weline_Theme::theme/frontend/assets/css/theme.css</theme:css>
<theme:js>Weline_Theme::theme/frontend/assets/js/theme.js</theme:js>
```

## 5. Partials 载入示例

```html
<w:block
    class="Weline\Theme\Block\Partials"
    area="frontend"
    type="footer"
    default-option="default"/>
```

规则：

- `area` 必须和当前主题 area 一致
- `type` 对应 `partials/<type>/`
- `default-option` 对应回退文件名

## 6. Slot / Widget 写法

### Slot 示例

```html
<w:slot
    id="content"
    name="内容区域"
    multiple="true"
    position="content"
    class="homepage-content-slot">
    {{meta.content}}
</w:slot>
```

### Widget 示例

```html
<w:widget
    type="product"
    name="featured-products"
    params='{"title":"推荐产品","limit":8}'/>
```

规则：

- 容器型布局优先用 `<w:slot>` 暴露可编辑区域
- 可配置部件优先用 `<w:widget>`
- `accept`、`multiple`、`wrapper`、`class` 等能力优先在 slot 上表达

## 7. CSS / JS 放置建议

### 优先放到 `assets/` 的情况

- area 级公共样式
- 多个 layout / partial / widget 共用的样式
- 模块加载、全局事件、通用初始化脚本

### 可保留内联的情况

- 强绑定当前 layout / partial / widget
- 需要被布局资源提取器统一收集
- 不会在多个文件重复出现

## 8. 硬性限制

- 自定义标签属性里不要写 PHP
  - 禁止：`<w:slot title="<?= __('标题') ?>">`
  - 改用：`title="@lang{标题}"`
- 用户可见文案必须走 `__()`、`<lang>`、`@lang`
- 颜色优先走变量，不要到处追加裸色

## 9. 修改后自查

- 注释是否完整
- 目录名、`@widget.type`、`@widget.code` 是否一致
- area 是否写对
- 模板是不是仍以展示逻辑为主
- 资源是不是放在了正确层级
