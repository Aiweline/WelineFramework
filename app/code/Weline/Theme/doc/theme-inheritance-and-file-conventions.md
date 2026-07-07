# Theme 继承与文件式约定

> 适用范围：主题继承、`app/design` 覆盖、模块 `view/theme` 资源贡献、模块模板覆盖、layout/partial/component/widget 文件落点。开发前先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。

## 实现来源

本规则来自当前代码实现，不是旧主题文档推导：

- `app/code/Weline/Theme/Model/WelineTheme.php`
- `app/code/Weline/Theme/Service/ThemeDirectoryResolver.php`
- `app/code/Weline/Theme/Helper/ThemePathResolver.php`
- `app/code/Weline/Theme/Helper/LayoutPathResolver.php`
- `app/code/Weline/Theme/Service/ThemeResourceCatalog.php`
- `app/code/Weline/Theme/Observer/TemplateFetchFile.php`

如果本文与源码冲突，以源码为准，并在同次任务修正文档。

## 主题路径模型

当前主题路径不是旧式 `design/frontend/default`。

| 场景 | 当前有效路径 |
|---|---|
| 默认主题源 | `app/code/Weline/Theme/view/theme/{frontend|backend}/...` |
| 设计主题主路径 | `app/design/{Vendor}/{theme}/{frontend|backend}/...` |
| 设计主题兼容路径 | `app/design/{Vendor}/{theme}/theme/{frontend|backend}/...` |
| 设计主题兼容路径 | `app/design/{Vendor}/{theme}/view/theme/{frontend|backend}/...` |
| 模块贡献主题资源 | `app/code/{Vendor}/{Module}/view/theme/{frontend|backend}/...` |
| 模块普通模板源 | `app/code/{Vendor}/{Module}/view/templates/{frontend|backend}/...` |
| 设计主题覆盖普通模板 | `app/design/{Vendor}/{theme}/{Module_Code}/templates/{frontend|backend}/...` |
| 设计主题覆盖普通模板兼容路径 | `app/design/{Vendor}/{theme}/{Vendor}/{Module}/templates/{frontend|backend}/...` |
| 编译/生成模板 | `view/tpl/`，禁止直接修改 |

## 继承链优先级

`WelineTheme::getThemeChain()` 返回基础主题到当前主题的链路。`ThemeDirectoryResolver::getAreaDirectories()` 在实际资源发现时按当前主题优先处理，然后父主题，再默认层，再模块贡献层。

同一逻辑 key 的主题资源优先级：

1. 当前 `app/design` 主题的 `{area}/...`
2. 父级 `app/design` 主题的 `{area}/...`
3. 兼容结构：`theme/{area}/...`
4. 兼容结构：`view/theme/{area}/...`
5. 默认主题：`app/code/Weline/Theme/view/theme/{area}/...`
6. 其他模块贡献层：`app/code/{Vendor}/{Module}/view/theme/{area}/...`

关键结论：

- `app/design` 当前主题可以覆盖父主题和默认主题。
- 父主题可以提供当前主题缺失的资源。
- `Weline_Theme/view/theme` 是默认层。
- 业务模块 `view/theme` 主要用于贡献新 layout/partial/component/widget，不应该被当成覆盖默认主题的首选位置。
- 同一逻辑 key 命中后，后面的层不会再覆盖它。

## 普通模板覆盖规则

普通模块模板指 `view/templates`，例如：

```text
app/code/Weline/Customer/view/templates/frontend/account/login.phtml
```

主题覆盖普通模板时，优先使用模块代码格式：

```text
app/design/{Vendor}/{theme}/Weline_Customer/templates/frontend/account/login.phtml
```

兼容格式：

```text
app/design/{Vendor}/{theme}/Weline/Customer/templates/frontend/account/login.phtml
app/design/{Vendor}/{theme}/Weline_Customer/view/templates/frontend/account/login.phtml
app/design/{Vendor}/{theme}/Weline/Customer/view/templates/frontend/account/login.phtml
```

不要把普通模板覆盖写进：

```text
app/design/{Vendor}/{theme}/frontend/templates/...
```

这个路径属于主题资源区域，不是模块普通模板覆盖协议。

## 主题资源文件约定

主题资源由 `ThemeResourceCatalog` 发现。资源类型和逻辑 key 如下：

| 类型 | 文件路径 | 逻辑 key |
|---|---|---|
| layout | `layouts/{layoutType}/{option}.phtml` | `layouts/{layoutType}/{option}` |
| layout 简写 | `layouts/{layoutType}.phtml` | `layouts/{layoutType}/default` |
| partial | `partials/{type}/{option}.phtml` | `partials/{type}/{option}` |
| partial 简写 | `partials/{type}.phtml` | `partials/{type}/default` |
| component | `components/{category}/{code}.phtml` | `components/{category}/{code}` |
| component 顶层 | `components/{code}.phtml` | `components/basic/{code}` |
| legacy widget | `widgets/{type}/{code}/default.phtml` | `components/{type}/{code}` |
| variables | `variables/_name.css` | `variables/name` |
| colors | `colors/_name.css` | `colors/name` |

注意：

- `variables/` 和 `colors/` 只扫描以下划线开头的 `.css` 文件。
- layout 旁边可以放同名 `.layout.json`，例如 `default.phtml` 对应 `default.layout.json`。
- 资源元数据优先来自 `@meta.*`、`@widget.*`、`@param`、`<w:slot>`、`data-wslot`。

## Layout 解析规则

layout 请求路径形态：

```text
theme/{area}/layouts/{layoutType}/{option}.phtml
```

解析过程：

1. 先找默认主题 `Weline_Theme/view/theme/{area}/layouts/...` 是否存在。
2. 如果默认主题有对应文件，再通过主题路径解析器查当前主题/父主题覆盖。
3. 如果具体 option 不存在，会尝试回退到同 layoutType 的 `default.phtml`。
4. 如果默认主题没有该 layout，再查模块贡献层 `view/theme/{area}/layouts/...`。

常见例子：

| 需求 | 正确文件 |
|---|---|
| 覆盖当前主题首页默认布局 | `app/design/WeShop/motor/frontend/layouts/homepage/default.phtml` |
| 覆盖当前主题首页简写布局 | `app/design/WeShop/motor/frontend/layouts/homepage.phtml` |
| 给模块贡献一个新商品布局 | `app/code/{Vendor}/{Module}/view/theme/frontend/layouts/product/custom.phtml` |
| 改框架默认首页布局 | `app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml` |

## 文件落点决策表

| 你要做什么 | 先改哪里 | 不要改哪里 |
|---|---|---|
| 修改某个设计主题外观 | `app/design/{Vendor}/{theme}/{area}/...` | `Weline_Theme/view/theme`，除非要改全局默认 |
| 修改框架默认主题能力 | `app/code/Weline/Theme/view/theme/{area}/...` | `view/tpl/`、`generated/` |
| 给业务模块新增可选 layout | `app/code/{Vendor}/{Module}/view/theme/{area}/layouts/...` | `app/design`，除非只属于某个设计主题 |
| 覆盖业务模块普通模板 | `app/design/{Vendor}/{theme}/{Module_Code}/templates/{area}/...` | `app/design/{Vendor}/{theme}/{area}/templates/...` |
| 新增 partial | 默认能力放 `Weline_Theme/view/theme/{area}/partials/...`，主题定制放 `app/design/.../{area}/partials/...` | layout 文件内堆重复片段 |
| 新增基础 UI 原语 | `components/` | widget 或 Taglib |
| 新增可视化编辑器部件 | 模板放 `view/theme/{area}/widgets/...`，注册放 `extends/module/Weline_Widget/{ModuleName}/widget.php` | 旧式 `extends/Weline_Widget/...` |
| 新增模板语义标签 | 对应模块 `Taglib/`，并读 `Weline_Taglib/doc/AI-INDEX.md` | 为普通页面片段滥建 Taglib |
| 改前端业务请求 | QueryProvider + `Weline.Api.*` | 禁止 `fetch`、`XMLHttpRequest`、`$.ajax`、`axios` |
| 看到问题在 `view/tpl` | 反查源模板、Taglib、Hook 或生成链路 | 直接编辑 `view/tpl` |

## AI 开发前必须回答

动手前先写出这 5 个答案：

1. 本次改动属于普通模板、主题资源、layout、partial、component、widget、Taglib 还是 Browser API？
2. 当前文件应该落在默认主题、设计主题、父主题、模块贡献层，还是普通模板覆盖层？
3. 真实源文件在哪里？是否误把 `view/tpl` 或 `generated/` 当源文件？
4. 是否涉及浏览器业务请求？如果涉及，后端 QueryProvider 和前端 `Weline.Api.*` 链路是什么？
5. 需要读哪些模块 `doc/AI-INDEX.md` 和专项文档？

这 5 个问题答不出来时，不要开始写代码。

## 反例

不要这样做：

```text
app/design/WeShop/motor/design/frontend/default/layout.html
app/design/WeShop/motor/etc/theme.xml
app/design/WeShop/motor/frontend/templates/account/login.phtml
app/code/Weline/Theme/view/tpl/zh_Hans_CN/theme/frontend/layouts/homepage/com_default.phtml
```

原因：

- 前两者来自旧主题模型。
- `frontend/templates/...` 不是模块普通模板覆盖协议。
- `view/tpl` 是编译结果，不是源文件。

## 推荐最小流程

1. 读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
2. 按任务读本文、`layout-discovery-guide.md`、`部件开发指南.md`、`widget-slot-attributes.md`、`Weline.Api使用指南.md`。
3. 用当前主题和 area 推导目标路径。
4. 只改源文件。
5. 改完后用 `rg` 检查没有新增 raw ajax/fetch、没有改 `view/tpl`、没有新增旧路径。
