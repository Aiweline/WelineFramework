# Theme 继承与文件式约定

> 适用范围：主题继承、`app/design` 覆盖、模块 `view/theme` 资源贡献、模块模板覆盖、layout/partial/component/widget 文件落点。开发前先调用 `resolve_task_context` 获取本任务所需约束。

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

### 全局资产与 Token overlay

`assets/css/theme.css`、`assets/js/theme.js` 是 Weline_Theme 的全局组件和运行时入口。活动设计主题不得以同逻辑 key 覆盖它们来复制 Card、Bootstrap adapter 或主题切换；同路径文件会使默认层完全失效。

设计主题若需要品牌化，只能提供颜色 palette overlay（例如 `colors/_light.css`、`colors/_dark.css`）并让组件继续引用 Weline 语义 Token。旧 `--theme-*` 和 `--admin-*` 可以作为兼容 alias，但不能成为第二套全局组件系统。

硬规则：`theme_design_must_not_override_core_runtime_assets`；操作细节见 [主题开发.md](../../../../../dev/ai-command/ai/主题开发.md) Mode B。

## 新建设计主题（操作摘要）

> 完整逐步清单与否决例见 [主题开发.md Mode B](../../../../../dev/ai-command/ai/主题开发.md)。开工前须声明 `work_mode=design_theme`。

1. **register.php**（样例 `app/design/Weline/hanfu/register.php`）：

```php
Register::register(
    TypeInterface::type,
    'Weline_YourTheme',
    [
        'name' => 'your-theme',
        'parent' => 'Default 默认主题', // 或已存在主题 name
        'path' => __DIR__,
    ],
    '1.0.0',
    '描述'
);
```

2. **现代目录**：必须含 `{frontend|backend}/`（`colors/_*.css`、`variables/_*.css`、独立 `assets/css/{brand}.css`）；禁止照抄旧 `view/templates` 脚手架；勿依赖过时的 `theme:create` 输出。

3. **安装 / 列表**：`php bin/w setup:upgrade` 或 `theme:install -t {name}` → `theme:listing`。

4. **激活**：`php bin/w theme:active {name} frontend`（**不是** `theme:activate`）。正式店面 **published `theme_binding` 优先于裸 `is_active`**。

5. **高压线**：禁止同 key 覆盖 `theme.css` / `theme.js`；模块模板走 `Weline_Module/templates/...`，禁止 `frontend/templates/`。

对照样例（非第二权威）：[hanfu 主题继承研究](../../../../../app/design/Weline/hanfu/doc/开发/主题继承研究.md)。

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
- **`layoutType` 可以含 `/`（嵌套）**：路径末段文件名永远是 `option`，其前全部目录段拼成 `layoutType`。例如 `layouts/account/login/default.phtml` → `layoutType=account/login`，`option=default`，逻辑 key `layouts/account/login/default`。禁止把该文件理解成 `layoutType=account` + `option=login/default`。

## Layout 解析规则

layout 请求路径形态：

```text
theme/{area}/layouts/{layoutType}/{option}.phtml
```

其中 `{layoutType}` 允许嵌套目录（如 `account/login`）。控制器写法对照：

| 控制器 `$layoutType` | 默认 option | 文件 |
|---|---|---|
| `homepage` | `default` | `layouts/homepage/default.phtml` |
| `account` | `dashboard` / `default`（按配置） | `layouts/account/{option}.phtml` |
| `account/login` | `default` | `layouts/account/login/default.phtml` |
| `account.auth`（点号，旧式） | 点号右侧为 option | `layouts/account/auth.phtml` |

规则：

- **斜杠 `/`**：整段是 `layoutType`，`option` 另取（未指定则为 `default`）。
- **点号 `.`**：左侧是 `layoutType`，右侧是 `option`（扁平 option，兼容旧入口如 Multipass `account.auth`）。
- 与公开路由对齐的认证页优先用嵌套：`account/login`、`account/register`、`account/forgot-password`、`account/set-password`、`account/social-login`。

解析过程：

1. 先找默认主题 `Weline_Theme/view/theme/{area}/layouts/...` 是否存在。
2. 如果默认主题有对应文件，再通过主题路径解析器查当前主题/父主题覆盖。
3. 如果具体 option 不存在，会尝试回退到同 layoutType 的 `default.phtml`（含嵌套 layoutType）。
4. 如果默认主题没有该 layout，再查模块贡献层 `view/theme/{area}/layouts/...`。

实现来源（冲突以源码为准）：`ThemeResourceCatalog::resolveTypeAndOption`、`LayoutScanner::scanLayoutsFromDir`、`LayoutPathResolver::buildLayoutPath` / `parseLayoutPath`、`ControllerFetchFileBefore`。

常见例子：

| 需求 | 正确文件 |
|---|---|
| 覆盖当前主题首页默认布局 | `app/design/WeShop/motor/frontend/layouts/homepage/default.phtml` |
| 覆盖当前主题首页简写布局 | `app/design/WeShop/motor/frontend/layouts/homepage.phtml` |
| 给模块贡献一个新商品布局 | `app/code/{Vendor}/{Module}/view/theme/frontend/layouts/product/custom.phtml` |
| 改框架默认首页布局 | `app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml` |
| 顾客登录页（模块贡献） | `app/code/Weline/Customer/view/theme/frontend/layouts/account/login/default.phtml` |

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
| 新增模板语义标签 | 对应模块 `Taglib/`，并通过 `resolve_task_context` 检索 `Weline_Taglib` | 为普通页面片段滥建 Taglib |
| 改前端业务请求 | QueryProvider + `Weline.Api.*` | 禁止 `fetch`、`XMLHttpRequest`、`$.ajax`、`axios` |
| 看到问题在 `view/tpl` | 反查源模板、Taglib、Hook 或生成链路 | 直接编辑 `view/tpl` |

## AI 开发前必须回答

动手前先写出这 5 个答案：

1. 本次改动属于普通模板、主题资源、layout、partial、component、widget、Taglib 还是 Browser API？
2. 当前文件应该落在默认主题、设计主题、父主题、模块贡献层，还是普通模板覆盖层？
3. 真实源文件在哪里？是否误把 `view/tpl` 或 `generated/` 当源文件？
4. 是否涉及浏览器业务请求？如果涉及，后端 QueryProvider 和前端 `Weline.Api.*` 链路是什么？
5. 需要读哪些模块 `resolve_task_context` 和专项文档？

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

1. 调用 `resolve_task_context` 获取本任务的最小上下文。
2. 按任务读本文、`layout-discovery-guide.md`、`部件开发指南.md`、`widget-slot-attributes.md`、`Weline.Api使用指南.md`。
3. 用当前主题和 area 推导目标路径。
4. 只改源文件。
5. 改完后用 `rg` 检查没有新增 raw ajax/fetch、没有改 `view/tpl`、没有新增旧路径。
