# Weline 框架 Taglib 标签目录（全量）

> 本文件是 `framework-taglib-catalog` 技能的权威标签清单。
> 来源：各模块 `Taglib/**` 中实现 `TaglibInterface` 的类 + `Weline\Framework\View\Taglib` 内置标签。
> 收集命令：`php bin/w taglib:collect`（或 `setup:upgrade` 触发的 registry collect）。
> **新增/更名/删除标签后必须同步更新本文件与 `SKILL.md` 场景映射表。**

- 模块自定义标签数：60
- 内置标签族：17（见下方「框架内置标签」）

## 命名约定

- 模板写法优先 `<w:{name} ...>`；多数内置标签同时支持无前缀写法（如 `<lang>`）。
- 模块标签名常用 `domain:entity:action`（如 `websites:website:select`、`i18n:language:select`）。
- 属性列中带 `*` 表示 `attr()` 声明为必需。
- 禁止在 `<w:*>` 属性中写 `<?= ?>` / `<?php ?>`；动态属性走框架允许的 `@lang()` / 变量编译约定。

## 框架内置标签（`Weline\Framework\View\Taglib`）

| 标签 | 分类 | 用途 | 示例 |
|---|---|---|---|
| `if / elseif / else` | 控制流 | 条件分支 | `<w:if condition="$x">...</w:if>` |
| `foreach / for / while` | 控制流 | 循环 | `<w:foreach name="$items" item="item">...</w:foreach>` |
| `switch / case` | 控制流 | 多分支 | `<w:switch value="$x"><w:case value="a">...</w:case></w:switch>` |
| `empty / notempty / has` | 控制流 | 空值判断包装 | `<w:empty name="$x">无数据</w:empty>` |
| `block` | 模板组合 | 渲染 Block 类/模板 | `<w:block class="Vendor\Module\Block\X" template="Vendor_Module::x.phtml"/>` |
| `template / include` | 模板组合 | 内嵌另一模板源文件 | `<w:template name="Vendor_Module::partial.phtml"/>` |
| `static` | 资源 | 解析 statics 路径为 URL/引入 | `<w:static>Vendor_Module::js/a.js</w:static>` |
| `js / css` | 资源 | 输出 script/link 标签 | `<js>Vendor_Module::assets/app.js</js>` |
| `lang` | 国际化 | 短语翻译（编译期或运行期） | `<lang>保存</lang> 或 @lang(保存)` |
| `hook` | 扩展点 | 渲染 Hook 挂载内容 | `<w:hook>header.after</w:hook>` |
| `var / pp / dd / count` | 调试/变量 | 输出变量或调试 | `<var>$name</var>` |
| `url / frontend-url / backend-url / api / backend-api / admin-url` | URL | 生成区域 URL | `<url>module/controller/action</url>` |
| `csrf` | 安全 | CSRF token 字段/值 | `<csrf/>` |
| `form` | 表单 | 统一 CSRF、可选 Captcha、扩展事件与运行时挂载；Captcha 默认关闭，只识别显式 `<w:form>` | `<w:form method="post" action="@var($saveUrl)">...</w:form>`；登录等入口显式加 `captcha="required"` 与 `intent` |
| `message / msg` | 提示 | 输出 MessageManager 消息 | `<message/>` |
| `string` | 文本 | 字符串处理辅助 | `见 Taglib.php string 项` |
| `pipeline / php` | 高级 | 管道/内联 PHP 编译辅助 | `框架内部/高级模板` |

## 模块自定义标签（按模块）

### Weline_Acl

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:acl>` | `Weline\Acl\Taglib\Acl` | source* | 按 ACL source 控制模板片段可见性 |
| `<w:acl:tag:select>` | `Weline\Acl\Taglib\TagSelect` | id*, name*, value, options, class, style, placeholder, empty-label, form, on-change | 后台选择 ACL Tag |

### Weline_Ai

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:ai:model:select>` | `Weline\Ai\Taglib\ModelSelect` | id*, name*, value*, display*, class, style, limit | 选择 AI 模型 |

### Weline_CacheManager

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:cache:clear>` | `Weline\CacheManager\Taglib\CacheClear` | — | 模板内触发缓存清理控件 |

### Weline_Cdn

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:select:account>` | `Weline\Cdn\Taglib\AccountSelect` | id*, name*, value*, display*, class, style, limit, url, provider_input | 选择 CDN 账户 |
| `<w:select:provider>` | `Weline\Cdn\Taglib\ProviderSelect` | id*, name*, value*, display*, class, style, limit, url | 选择 CDN 供应商 |

### Weline_DataTable

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:d-form>` | `Weline\DataTable\Taglib\Form` | model, scope, id, action, method, mode, record_id, title, form-mode, form-title, show-trigger-button, button-text, button-class, button-icon | 声明式 DataTable 表单 |
| `<w:d-table>` | `Weline\DataTable\Taglib\Table` | model*, scope*, join, id, class, style, editable, inline-edit, modal-edit, searchable, sortable, page-size, show-pagination, show-toolbar | 声明式 DataTable |
| `<w:field>` | `Weline\DataTable\Taglib\Field` | name*, belong*, sortable, url, multi, icon, width, min-width, max-width, resizable, visible, editable, searchable, type | DataTable 列字段（须在 d-table 内） |
| `<w:t-body>` | `Weline\DataTable\Taglib\TableBody` | scope, model, editable, inline-edit, modal-edit, selectable, multi-select, row-actions, empty-text, loading-text | DataTable 表体分区 |
| `<w:t-filter>` | `Weline\DataTable\Taglib\TableFilter` | scope, model, searchable, advanced, collapsible | DataTable 筛选分区 |
| `<w:t-footer>` | `Weline\DataTable\Taglib\TableFooter` | scope, model, show-pagination, show-summary, show-actions | DataTable 表尾分区 |
| `<w:t-header>` | `Weline\DataTable\Taglib\TableHeader` | scope, model, sortable, draggable, configurable, resizable | DataTable 表头分区 |

### Weline_EditorManager

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:ckeditor>` | `Weline\EditorManager\Taglib\CKEditor` | — | CKEditor 兼容标签 |
| `<w:editor-manager>` | `Weline\EditorManager\Taglib\EditorManager` | container-id* | 富文本编辑器容器 |

### Weline_FileManager

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:file-manager>` | `Weline\FileManager\Taglib\FileManager` | code, title*, target*, path*, lockPath, setAttr, value*, vars, ext*, multi, w, h, size | 文件/媒体选择器 |
| `<w:file-manager-connector>` | `Weline\FileManager\Taglib\FileManagerConnector` | code, target, close, title, path*, ext*, value, vars, multi, w, h, size, recommend_width, recommend_height | 文件管理器连接器 |
| `<w:file-view>` | `Weline\FileManager\Taglib\FileVIew` | type, vars*, value*, width, height | 文件预览 |

### Weline_Geo

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:geo>` | `Weline\Geo\Taglib\Geo` | slot | 地理信息插槽 |

### Weline_Inquiry

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:inquiry>` | `Weline\Inquiry\Taglib\Inquiry` | code*, mode, id, trigger-selector, custom-css, custom-js | 渲染已发布的独立询盘表单；支持 inline/modal/trigger |

### Weline_I18n

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:i18n:language:select>` | `Weline\I18n\Taglib\LanguageSelect` | id*, name, value, multiple, class, style, required, allow-empty, display-only, readonly-values, allowed-values, option-values, options-values, display-locale | 按国家分组选择可用语言/Locale；搜索覆盖国家、语言和代码 |
| `<w:i18n:switcher>` | `Weline\I18n\Taglib\LanguageSwitcher` | for, allowed-values, option-values, options-values, locales, current, navigation, website-id, show-request, label-mode | 按国家分组切换当前界面语言；与 LanguageSelect 共享语言目录。旧名 `<w:i18n:language:switcher>` 为兼容别名 |
| `<w:local>` | `Weline\I18n\Taglib\Local` | model*, id*, field* | 按 LocalModel 字段输出可翻译本地化文案 |

### Weline_Meta

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:meta>` | `Weline\Meta\Taglib\Meta` | — | @meta{key\|default} 读取元数据 |
| `<w:meta>` | `Weline\Meta\Taglib\WMeta` | type, prefix, scope | Meta 翻译/展示 |
| `<w:meta-manager>` | `Weline\Meta\Taglib\MetaManager` | namespace, area, scope, locale, identity-id, type, category, show-filters, show-tree, default-namespace, on-save, max-depth, min-depth, dir-config-callback | Meta 配置管理 UI |

### Weline_ModuleManager

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:module-manager:module:select>` | `Weline\ModuleManager\Taglib\ModuleSelect` | id*, name*, value, options, class, style, placeholder, empty-label, allow-empty, form, on-change, clearable | 选择已安装模块 |

### Weline_Seo

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:seo>` | `Weline\Seo\Taglib\Seo` | slot, once | SEO 插槽/输出 |
| `<w:seo:account:select>` | `Weline\Seo\Taglib\AccountSelect` | id*, name*, value*, display*, class, style, limit, url | 选择 SEO 平台账户 |
| `<w:seo:manager>` | `Weline\Seo\Taglib\Manager` | module*, scope, id, height, class, style, title | 嵌入 SEO 管理面板 |

### Weline_Taglib

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:breadcrumb>` | `Weline\Taglib\Taglib\Breadcrumb` | model, source, action_field, id_field, parent_field, order_field, name_field | 面包屑 |
| `<w:css:part>` | `Weline\Taglib\Taglib\CssPart` | name | CSS 片段分区 |
| `<w:js:part>` | `Weline\Taglib\Taglib\JsPart` | name | JS 片段分区 |
| `<w:scope>` | `Weline\Taglib\Taglib\Scope` | container-id*, url, event | 局部作用域容器 |
| `<w:test_test>` | `Weline\Taglib\Taglib\Test` | — | Taglib 自测示例（勿用于业务） |

### Weline_Theme

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:icon>` | `Weline\Theme\Taglib\Icon` | name*, size, label, class | 输出 Weline UI 语义化内联 SVG 图标；无 label 时为装饰图标 |
| `<w:slot>` | `Weline\Theme\Taglib\Slot` | id, name, accept, reject, exclusive, multiple, max, min, position, required, append, prepend, wrapper, class | 主题挂载点 / section 宿主 |
| `<w:theme:address>` | `Weline\Theme\Taglib\Address` | id, for, code, name, country-name, province-name, city-name, district-name, country, province, city, district, cascade, searchable | 省市区地址联动 |
| `<w:theme:cascader>` | `Weline\Theme\Taglib\Cascader` | id*, name*, url, options, value, value-field, label-field, children-field, placeholder, separator, lazy, lazy-url, multiple, check-strictly | 级联选择器 |
| `<w:theme:color-picker>` | `Weline\Theme\Taglib\ColorPicker` | id*, name*, value, format, presets, show-alpha, show-input, clearable, class, style, disabled, required | 颜色选择器 |
| `<w:theme:css>` | `Weline\Theme\Taglib\ThemeCss` | — | 加载主题 CSS |
| `<w:theme:date-range>` | `Weline\Theme\Taglib\DateRangePicker` | id*, start-name*, end-name*, start-value, end-value, format, type, placeholder-start, placeholder-end, separator, shortcuts, min-date, max-date, clearable | 日期范围选择 |
| `<w:theme:icon-picker>` | `Weline\Theme\Taglib\IconPicker` | id*, name*, value, placeholder, clearable, class, disabled, required | 只保存 Weline UI 语义图标名的可搜索图标选择器 |
| `<w:theme:js>` | `Weline\Theme\Taglib\ThemeJs` | — | 加载主题 JS |
| `<w:theme:modal>` | `Weline\Theme\Taglib\Modal` | id*, title, size, closable, backdrop, centered, class, style | 主题 Modal 容器 |
| `<w:theme:search-select>` | `Weline\Theme\Taglib\SearchSelect` | id*, name*, url, options, value, value-field, label-field, placeholder, debounce, min-chars, class, style, disabled, required | 通用远程/本地搜索下拉 |
| `<w:theme:sse-progress>` | `Weline\Theme\Taglib\SseProgress` | id*, url, steps | SSE 进度条 |
| `<w:theme:sse-terminal>` | `Weline\Theme\Taglib\SseTerminal` | id*, url, path, title, height, events, auto-scroll, show-timestamp, show-toolbar, show-start-toggle, allow-html, class, style, max-stream-chars | SSE 终端输出 |
| `<w:theme:tag-input>` | `Weline\Theme\Taglib\TagInput` | id*, name* | 标签输入 |
| `<w:theme:template>` | `Weline\Theme\Taglib\ThemeTemplate` | enable, layout | 加载主题配置模板 |
| `<w:theme:tree-select>` | `Weline\Theme\Taglib\TreeSelect` | id*, name*, url, options, value, value-field, label-field, children-field, placeholder, multiple, checkable, check-strictly, default-expand-all, searchable | 树形选择器 |

### Weline_Visitor

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:pixel>` | `Weline\Visitor\Taglib\Pixel` | name, enabled | 访客像素埋点引导 |

### Weline_Vue

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:v>` | `Weline\Vue\Taglib\Vue` | — | Vue 插值包装 |

### Weline_Websites

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:websites:channel:select>` | `Weline\Websites\Taglib\ChannelSelect` | id*, name*, value, options, class, style, placeholder, empty-label, allow-empty, form, on-change, clearable | 后台/表单需要选择销售渠道 Channel |
| `<w:websites:domain:select>` | `Weline\Websites\Taglib\DomainSelect` | id*, name*, value*, display*, class, style, limit, url, multiple, on-select, auto-fill-code, auto-fill-url, auto-fill-name, auto-fill-address | 后台/表单需要选择域名 |
| `<w:websites:registrar:select>` | `Weline\Websites\Taglib\RegistrarSelect` | id*, name*, value, display, class, style, placeholder, empty-label, options, multiple, on-select | 后台/表单需要选择域名注册商 |
| `<w:websites:store:select>` | `Weline\Websites\Taglib\StoreSelect` | id*, name*, value, options, class, style, placeholder, empty-label, allow-empty, form, on-change, clearable | 后台/表单需要选择店铺 Store |
| `<w:websites:website:build>` | `Weline\Websites\Taglib\BuildSite` | id*, mode, action, title, target-button-text, target-button-class, icon, direction, class-names, close-button-show, close-button-text, save, vars, action-params | 触发建站 OffCanvas/构建流程 UI |
| `<w:websites:website:form>` | `Weline\Websites\Taglib\WebsiteForm` | id*, website, locales, currencies, timezones, selected_currencies, selected_languages, selected_pool_ids, form_action, show_save_btn, save_btn_text, cancel_url | 嵌入完整建站/编辑站点表单片段 |
| `<w:websites:website:select>` | `Weline\Websites\Taglib\WebsiteSelect` | id*, name*, value, display, class, style, placeholder, empty-label, options, multiple, allow-empty, clearable, on-select, on-change | 后台/表单需要选择站点（含零号 default） |

### Weline_Widget

| 标签 | 类 | 主要属性 | 场景/说明 |
|---|---|---|---|
| `<w:widget>` | `Weline\Widget\Taglib\Widget` | type*, name*, params, block-class, template, id | 渲染已注册 Widget |

## 维护

1. 新增标签类到模块 `Taglib/` 并实现 `TaglibInterface`。
2. 运行 `php bin/w taglib:collect`（或含 collect 的 `setup:upgrade`）。
3. **同步更新本目录**：标签名、模块、属性、场景说明、示例。
4. **同步更新** `dev/ai/skills/framework-taglib-catalog/SKILL.md` 中的「场景 → 标签」映射（若属于选择器/表单控件类场景）。
5. 若标签有模块专项文档，在该模块 `doc/` 中交叉引用本目录。
