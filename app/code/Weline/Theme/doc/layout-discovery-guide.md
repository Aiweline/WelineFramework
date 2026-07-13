# Theme Layout Discovery Guide

本文说明开发者如何为 Theme 模块新增布局、覆盖布局、配置默认 slot/widget 信息，以及如何验证布局是否被正确发现。

## 适用场景

- 业务模块要新增一个可选页面布局。
- 设计主题要覆盖 `Weline_Theme` 的默认布局。
- 设计主题要新增只属于当前主题的布局。
- 布局需要携带默认 widget/slot 配置，用于主题编辑器或默认布局种子。

## 发现优先级

布局的逻辑 key 是 `layouts/{layoutType}/{option}`。例如：

```text
layouts/homepage/default
layouts/product/default
layouts/codex_module_only/default
```

同一个逻辑 key 只取第一个命中项。发现顺序固定如下：

1. 当前 `app/design` 主题链，包含父主题到当前主题，当前主题优先。
2. `Weline_Theme/view/theme` 默认布局。
3. 其他模块的 `view/theme` 贡献目录。

因此规则是：

- `app/design` 可以覆盖 `Weline_Theme` 默认布局。
- 模块 `view/theme` 只能追加新布局，不能覆盖 `Weline_Theme` 默认布局。
- 模块之间提供同名逻辑 key 时，先进入模块列表的模块获胜。不要依赖这个行为来做覆盖。

## 目录约定

### Weline_Theme 默认布局

默认布局放在 Theme 模块内：

```text
app/code/Weline/Theme/view/theme/frontend/layouts/{layoutType}/{option}.phtml
app/code/Weline/Theme/view/theme/backend/layouts/{layoutType}/{option}.phtml
```

示例：

```text
app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml
```

### 业务模块新增布局

业务模块使用同样的 `view/theme` 结构：

```text
app/code/{Vendor}/{Module}/view/theme/frontend/layouts/{layoutType}/{option}.phtml
app/code/{Vendor}/{Module}/view/theme/backend/layouts/{layoutType}/{option}.phtml
```

示例：

```text
app/code/Codex/ThemeLayoutDemo/view/theme/frontend/layouts/codex_module_only/default.phtml
```

如果模块提供的逻辑 key 已经存在于 `Weline_Theme`，模块文件会被扫描为 raw candidate，但不会成为 resolved layout。要追加布局，请使用新的 `layoutType` 或新的 `option`。

### app/design 覆盖或新增布局

设计主题支持三种结构，推荐使用第一种或第二种：

```text
app/design/{Vendor}/{theme}/frontend/layouts/{layoutType}/{option}.phtml
app/design/{Vendor}/{theme}/theme/frontend/layouts/{layoutType}/{option}.phtml
app/design/{Vendor}/{theme}/view/theme/frontend/layouts/{layoutType}/{option}.phtml
```

示例：

```text
app/design/Codex/demo-theme/theme/frontend/layouts/homepage/default.phtml
```

当 design 主题和 `Weline_Theme` 或模块提供相同逻辑 key 时，design 主题获胜。

## layout.phtml 要求

布局文件是源模板，禁止编辑编译后的 `view/tpl` 或 `generated/` 文件。

推荐在文件顶部声明元信息和参数：

```php
<?php
/**
 * @meta.name {default="Module Only Demo",name="Module Only Demo",description="Layout contributed by a module view/theme directory"}
 * @param.title {default="Module Only Demo",name="Page Title",description="Page title"}
 * @param.content {default="",name="Content HTML",description="Main content"}
 */
```

布局中的可编辑区域用 `<w:slot>` 暴露：

```html
<w:slot id="content" area="content" label="Content">
    <main class="w-container">
        <w:slot id="custom-main" area="content" label="Main content">
            <?= (string)($meta['content'] ?? '') ?>
        </w:slot>
    </main>
</w:slot>
```

注意：

- `.phtml` 文件不要加入 `declare(strict_types=1);`。
- 用户可见文本使用 `__()`。
- 新模板优先使用 Theme 的 `w-*` 默认样式类。
- 大段 CSS/JS 不要塞进布局文件，优先放到主题资产或 scoped partial。

## layout.json 默认配置

布局可以携带同名 `*.layout.json`。文件名必须和 `.phtml` 对应：

```text
default.phtml
default.layout.json
```

示例：

```json
{
  "page_type": "codex_module_only",
  "source": "module",
  "widgets": [
    {
      "area": "content",
      "slot_id": "codex-module-main",
      "widget_code": "hero-slider",
      "widget_module": "Weline_Theme",
      "widget_type": "banner",
      "config": {
        "title": "Module only layout widget"
      },
      "sort_order": 10
    }
  ]
}
```

`*.layout.json` 跟随 `.phtml` 的同一套发现优先级：

- design 覆盖布局时，design 旁边的 `layout.json` 也覆盖。
- `Weline_Theme` 默认布局存在时，模块同名 `layout.json` 不会覆盖默认布局。
- 模块新增布局时，模块旁边的 `layout.json` 会被加载。

`DefaultLayoutSeeder` 读取 `ThemeResourceCatalog` 的 resolved layout，因此默认 widget 配置也遵循相同优先级。

## 新增布局步骤

### 在业务模块中追加布局

1. 确认模块已注册并出现在 `app/etc/modules.php`。
2. 新建 `view/theme/{area}/layouts/{layoutType}/{option}.phtml`。
3. 可选：新建同名 `{option}.layout.json`。
4. 不要使用已存在的 `Weline_Theme` 逻辑 key 来做覆盖。
5. 如果是新模块，执行 `php bin/w setup:upgrade` 让模块进入注册表。没有新增 Controller 时不需要 `--route`。
6. 运行 discovery 验证，确认 `layer_type=module`。

### 在 app/design 中覆盖布局

1. 在设计主题下创建同逻辑 key 的 `.phtml`。
2. 可选：创建同名 `*.layout.json`。
3. 清理 Theme runtime cache 或重启相关 WLS 实例。
4. 运行 discovery 验证，确认 `layer_type=theme` 且路径来自 `app/design`。

## 验证建议

基础语法：

```bash
php -l app/code/{Vendor}/{Module}/view/theme/frontend/layouts/{layoutType}/{option}.phtml
```

聚焦测试：

```bash
php bin/w phpunit:run --name=LayoutPathResolverTest --stop-on-failure
```

运行时验证建议直接检查 `ThemeResourceCatalog`：

- `getLayoutResource('frontend', $theme, '{layoutType}', '{option}')`
- resolved resource 的 `layer_type`
- resolved resource 的 `file_path`
- resolved resource 的 `layout_info`

如果要验证页面可达，再补充：

```bash
php bin/w http:request /
```

只有在具体路由会真实渲染该 layout shell 时，HTTP 或 Browser 冒烟才可以作为布局渲染证据。仅返回 preview content 容器的接口不能证明 layout shell 已渲染。

后台主题编辑器收到前台布局类型（例如 `homepage/default`）时，预览路由会优先回退到
`backend/layouts/dashboard/default.phtml`；仅在 Dashboard 布局不可用时才回退到
`backend/layouts/default/default.phtml`。回退后必须同步更新预览上下文的 `target_value`，
避免布局文件与草稿/插槽数据仍使用不同的页面类型。

后台 Dashboard 预览必须渲染真实后台壳层，包括 Header、导航/侧栏、内容区域、Footer、
右侧配置层及后台公共静态资源；编辑模式不能用独立的简化 HTML 画布替代正式布局。
预览页额外显示固定的“预览模式”提示与“退出预览”入口，该提示只由编辑器预览注入，
不得进入正式后台页面。

## Demo 参考

本仓库提供了两个最小示例：

```text
app/code/Codex/ThemeLayoutDemo/view/theme/frontend/layouts/codex_module_only/default.phtml
app/code/Codex/ThemeLayoutDemo/view/theme/frontend/layouts/codex_module_only/default.layout.json
app/design/Codex/demo-theme/theme/frontend/layouts/homepage/default.phtml
app/design/Codex/demo-theme/theme/frontend/layouts/codex_design/default.phtml
app/design/Codex/demo-theme/theme/frontend/layouts/codex_design/default.layout.json
```

验证目标：

- `codex_module_only/default` 来自模块，证明模块可以追加新布局。
- 模块里的 `homepage/default` 不会覆盖 `Weline_Theme` 或 design。
- `app/design/Codex/demo-theme/theme/frontend/layouts/homepage/default.phtml` 会覆盖默认 homepage。
