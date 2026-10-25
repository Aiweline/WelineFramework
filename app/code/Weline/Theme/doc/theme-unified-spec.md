# 主题统一规范

> 适用范围：`Weline_Theme` 默认主题、`app/design` 设计主题、模块 `view/theme` 贡献层，以及主题 Token、布局、片段、部件、色盘、静态资源的开发与迁移。
>
> 权威关系：本规范是主题开发的**统一入口**；细则仍以各专项文档与源码为准。两者冲突时以源码为准，并在同次任务修正文档。
>
> 本规范正文不出现任何具体主题名、品牌名或站点名。示例统一使用占位符：`{Vendor}`、`{theme}`、`{area}`、`{brand}`。

## 0. 一句话

**默认主题只提供机制与默认信息；设计主题只提供品牌信息；两者通过同逻辑 key 的文件覆盖衔接，任何一层都不复制另一层的实现。**

### 0.1 先判三件事

```text
这次改的是机制、品牌，还是运行时 PHP？
├─ 机制/通用壳/Token/默认部件        → work_mode=default_theme
├─ 某一主题的配色/皮肤/专属模板/素材  → work_mode=design_theme（只落 app/design）
└─ Preview/Editor/Resolver/Installer → work_mode=theme_module_runtime
```

- 单波单 mode；跨 mode 必须拆波并分别验收。
- 设计主题需要改默认层才能达成 → **停手**，判定为框架/默认层缺口，不静默改上游。
- 默认层出现设计主题内容 → 按 §8 迁出，不直接删除重写。

## 1. 两层模型

| 层 | 路径 | 职责 |
|---|---|---|
| 默认主题 | `app/code/Weline/Theme/view/theme/{area}/...` | 布局/片段/组件/部件机制、默认 Token、默认色盘、全局 CSS/JS 入口、主题运行时 |
| 设计主题 | `app/design/{Vendor}/{theme}/{area}/...` | 品牌色盘、品牌变量、品牌独立 CSS/JS、同 key 覆盖布局/片段/组件/部件、品牌 head 资源 |
| 模块贡献 | `app/code/{Vendor}/{Module}/view/theme/{area}/...` | 追加业务布局/片段/组件/部件；不覆盖默认主题 |

`{area}` 只能是 `frontend` 或 `backend`。

**硬边界**

- 默认主题**不得**出现任何设计主题的品牌资产、业务数据、专属模板、专属服务或专属命名。
- 设计主题**不得**修改 `app/code/**` 上游来达成外观；只能在自己的 `app/design` 树内覆盖。
- 模块贡献层**不得**覆盖默认主题的同 key 资源；只能追加新 key。

## 2. 默认主题准入清单

### 2.1 允许

| 类别 | 示例 |
|---|---|
| 页面骨架 | `layouts/{layoutType}/{option}.phtml`，跨站通用壳 |
| 可复用片段 | `partials/{type}/{option}.phtml` |
| 基础组件 | `components/{code}.phtml` / `components/{category}/{code}.phtml` |
| 通用部件 | `widgets/{type}/{code}/default.phtml`，部件元数据写 `@widget.*` |
| 默认 Token | `variables/_spacing.css`、`_typography.css`、`_colors.css`、`_borders.css`、`_shadows.css` |
| 默认模式色盘 | `colors/_default.css`、`_light.css`、`_dark.css` |
| 通用风格选项 | 不绑定任何设计主题的语义色盘、表面/密度选项；必须可被任意主题继承 |
| 架构 CSS | `assets/css/theme.css`：版心、表面机制、全局组件契约、Token 桥接、无障碍/运动护栏 |
| 运行时 JS | `assets/js/theme.js`：主题切换、全局运行时 |
| 通用部件资产 | `view/statics/css/widgets/*`、`view/statics/js/widgets/*`：只服务通用部件机制 |

### 2.2 禁止

| 禁止项 | 判定 |
|---|---|
| 设计主题的品牌色盘/品牌变量/品牌 CSS | 出现 `colors/_*.css` 或 `variables/_*.css` 只为某一设计主题服务 |
| 设计主题的专属模板 | 默认 `layouts/`、`partials/`、`widgets/` 中出现某一主题的业务内容或专属结构 |
| 设计主题的专属服务/数据 | 默认 `Service/`、`Helper/`、`extends/` 中出现某一主题的目录、文案、SEO、素材映射 |
| 设计主题的专属静态资源 | 默认 `view/statics/`、`view/theme/{area}/assets/` 中出现某一主题的图片、字体、皮肤叶 |
| 以设计主题命名的类/文件/选择器 | 新增文件中出现 `{theme}-*`、`{brand}-*`、`{站点}-*` 命名 |
| 在默认 CSS 中写设计主题选择器 | 例如 `.categories-sidebar .{theme}-chrome__*` |
| 在默认布局的 `accept` 中登记设计主题专属部件 | 设计主题在自己的同 key 布局覆盖里登记 |
| 同 key 覆盖 `assets/css/theme.css` / `assets/js/theme.js` | 任何设计主题都不允许；会顶空全局机制 |

> 历史存量（既有厂商命名、既有默认皮肤、多主题共用的 auth 画布素材）不在本规范的即时清理范围内；**新增**一律禁止，存量按独立迁移波次处理。

## 3. 设计主题准入清单

### 3.1 必备结构

```text
app/design/{Vendor}/{theme}/
├── register.php
├── {area}/
│   ├── colors/_*.css                 # 品牌色盘
│   ├── variables/_*.css              # 品牌尺寸/字体/阴影覆盖（可选）
│   ├── assets/css/{brand}*.css       # 品牌独立 CSS（走 overlay）
│   ├── assets/js/{brand}*.js         # 品牌独立 JS（可选）
│   ├── layouts/...                   # 同 key 覆盖（可选）
│   ├── partials/...                  # 同 key 覆盖（可选）
│   ├── components/...                # 同 key 覆盖（可选）
│   ├── widgets/...                   # 设计主题自有/覆盖部件（可选）
│   └── partials/head/theme-head-css-overlay.php  # 品牌 CSS 清单（可选）
├── {Module_Code}/templates/{area}/... # 模块普通模板覆盖（可选）
└── {Module_Code}/email/...            # 邮件模板覆盖（可选）
```

兼容路径 `theme/{area}/...`、`view/theme/{area}/...` 仍可被发现，但新主题**只写现代 `{area}/` 树**。

### 3.2 允许

- 品牌色盘：只改 `colors/_*.css` 中的品牌叶与语义别名，不重建组件系统。
- 品牌变量：只改 `variables/_*.css` 中的尺寸、字体、圆角、阴影、画布 Token。
- 品牌独立 CSS：`assets/css/{brand}*.css`，通过 `partials/head/theme-head-css-overlay.php` 进入 `PACK_OVERLAY`。
- 同 key 覆盖：布局、片段、组件、部件模板；覆盖文件必须保持原 key 的契约（`@meta.*`、`@param.*`、`@widget.*`、`weline-code`）。
- 设计主题自有部件：`{area}/widgets/{type}/{code}/default.phtml`，由主题资源发现链扫描。

### 3.3 禁止

- 同 key 覆盖 `assets/css/theme.css` 或 `assets/js/theme.js`。
- 把品牌皮肤写成默认主题的全局选择器，或反向修改默认主题来适配品牌。
- 在 `{area}/templates/**` 放模块普通模板（那是主题资源区，不是模块模板覆盖协议）。
- 把模块普通模板镜像进默认 `Weline/Theme/view/theme/{Module}/`。
- 用 `!important` 与默认骨架对抗；缺 Token 时先补 Token，不压骨架。
- 在元素 class 或全局 CSS 文件名里写厂商名/站点名表达风格；风格由色盘/`data-theme`/品牌叶选择。

## 4. 文件覆盖契约

### 4.1 资源类型与逻辑 key

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

- `variables/` 与 `colors/` 只扫描下划线开头的 `.css`。
- `layoutType` 可含 `/`：路径末段文件名永远是 `option`，其前全部目录段拼成 `layoutType`。
- layout 旁可放同名 `.layout.json`，发现优先级与 `.phtml` 一致。

### 4.2 发现优先级

同一逻辑 key：

1. 当前 `app/design` 主题的 `{area}/...`
2. 父级 `app/design` 主题的 `{area}/...`
3. 兼容结构 `theme/{area}/...`
4. 兼容结构 `view/theme/{area}/...`
5. 默认主题 `app/code/Weline/Theme/view/theme/{area}/...`
6. 其他模块 `app/code/{Vendor}/{Module}/view/theme/{area}/...`

**首个命中即胜出**，后续层不再覆盖。设计主题可以覆盖默认主题；模块贡献只能追加。

### 4.3 模块普通模板覆盖

```text
app/design/{Vendor}/{theme}/{Module_Code}/templates/{area}/{path}.phtml
```

兼容格式：

```text
app/design/{Vendor}/{theme}/{Vendor}/{Module}/templates/{area}/{path}.phtml
app/design/{Vendor}/{theme}/{Module_Code}/view/templates/{area}/{path}.phtml
app/design/{Vendor}/{theme}/{Vendor}/{Module}/view/templates/{area}/{path}.phtml
```

**不要**写进 `app/design/{Vendor}/{theme}/{area}/templates/...`。

### 4.4 邮件模板覆盖

```text
app/design/{Vendor}/{theme}/{Module_Code}/email/...
```

与普通模板同一继承链；`theme:upgrade` / `pub/static` 不发布 `templates/**` 与 `email/**`。

## 5. 资源加载顺序与静态命名空间

### 5.1 Head CSS 顺序（前台）

```text
1) layout-critical                 极小骨架（独立）
2) PACK_TOKENS                     variables + default/ink/light 色盘
3) PACK_STOREFRONT                 foundation → frontend → theme.css → dark
4) Disk override                   主题配置合并 CSS
5) PACK_OVERLAY                    设计主题品牌 CSS（theme-head-css-overlay.php）
6) 大布局包                        widget layout_source 合包
7) 其他 source CSS
```

- 品牌 CSS **必须**进 `PACK_OVERLAY`，不得塞进 `theme.css` 或 `PACK_STOREFRONT`。
- `theme-head-css-overlay.php` 返回 `common` + `layouts` 两组；`layouts[{layoutType}]` 只在该布局加载。
- merge=off 的皮肤 fallback 必须与 overlay 清单逐项同构，避免两条加载路径漂移。

### 5.2 静态命名空间

| 资源 | 发布地址 |
|---|---|
| 默认主题 CSS/JS | `/static/Weline/Theme/...`（按既有规则） |
| 设计主题 CSS/JS | `/static/{Vendor}/{theme}/{area}/assets/...` |
| 设计主题 CSS/JS 镜像 | `/static/{Vendor}/{theme}/Weline/Theme/view/theme/{area}/...`（发布步骤双写） |
| 设计主题图片/字体 | 只发 `/static/{Vendor}/{theme}/{area}/assets/...`，不双写进 Theme 镜像 |
| 模块静态资源 | `/static/{Vendor}/{Module}/...` |

- `@widget.source` / `@widget.layout_source` 只解析**模块命名空间** `Vendor_Module::css|js/...`；设计主题私有资产不能写进这两个字段。
- 设计主题私有 CSS/JS 走 `theme:css` / `theme:js`、`theme-head-css-overlay.php` 或设计主题 head partial。
- 图片 URL 用设计区 ns 路径；需要缓存破坏时加内容指纹 `?v=`，不要依赖 build 前缀。
- 设计主题私有 CSS 若只存在于 `{area}/assets/` 而 build 前缀镜像尚未发布，用**设计区 ns 直链**（如 `V::asset()`）从设计主题 head/skin 加载；不要塞进 overlay 的镜像 URL，否则 `/static/{build}/.../Weline/Theme/...` 会 404。
- 从默认主题迁出的部件 CSS：由设计主题 head/skin 直链加载；**不要**为了 `@widget.source` 把设计主题资产写回默认 `view/statics`。

## 6. Token、命名与内容边界

1. **Token 优先**：颜色、间距、字号、圆角、阴影、版心、画布只消费 `--weline-*` / `--color-*` 语义 Token；禁止裸 hex、裸 px 间距。
2. **版心只认两个 Token**：`--weline-container-gutter` 与 `--weline-layout-content-max-width`；唯一实现 `.w-container` / `.w-theme-content-width`。
3. **画布只设五个 Token**：`--weline-page-canvas-{color,image,size,repeat,position}`；禁止直接写 `body{background-image}` 或用 `!important` 压骨架。
4. **风格用文件选择**：配色/皮肤由色盘文件、`data-theme`、品牌叶决定；禁止用厂商名修饰 class。
5. **默认层禁 `!important`**：视觉/布局声明靠级联、特异度、Token 叶子；历史 `!important` 不扩大。
6. **内容归位**：业务文案、商品目录、站点 SEO、品牌故事、专属素材属于站点/设计主题，不进默认主题。

## 7. 新建设计主题

1. **注册** `register.php`：

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
    '主题描述'
);
```

2. **现代目录**：至少 `{area}/colors/_*.css`、`{area}/variables/_*.css`、`{area}/assets/css/{brand}*.css`；不要照抄旧 `view/templates` 脚手架。
3. **安装/列表**：`php bin/w setup:upgrade` 或 `php bin/w theme:install -t {theme}` → `php bin/w theme:listing`。
4. **绑定店面**：在「网站信息 → 店面主题」写入 `websites_theme_application`；`theme:active` / `is_active_*` 不是店面权威。
5. **品牌 head**：写 `{area}/partials/head/theme-head-css-overlay.php`，把品牌 CSS 放入 `common` 或对应 `layouts`。
6. **发布**：`php bin/w theme:upgrade -t {theme}`；按需 `php bin/w theme:disk:compile`。
7. **验收**：默认主题不被修改；设计主题 diff 只在 `app/design/{Vendor}/{theme}`；店面截图与预览三态同构。

## 8. 从默认主题迁出品牌内容

当默认主题已经嵌入了某个设计主题的内容，按以下顺序迁移，**不要**直接删除后重写：

1. **盘点**：按 key 列出默认主题中的品牌文件/片段/部件/资产/服务/数据。
2. **同 key 复制**：在 `app/design/{Vendor}/{theme}/{area}/...` 建同逻辑 key 文件；布局/片段/组件/部件保持原 `@meta`/`@param`/`@widget` 契约。
3. **设计主题加载**：品牌 CSS 进 `theme-head-css-overlay.php`；品牌 JS 进设计主题 head/suffix；品牌图片进 `{area}/assets/images/...`。
4. **默认层还原**：删除默认主题中的品牌文件、品牌选择器、品牌 `accept` 登记、品牌服务/数据；默认布局恢复通用结构。
5. **引用同步**：更新设计主题模板、head、测试、静态映射中的旧路径；`@widget.source` 只保留模块命名空间资源。
6. **守卫**：新增/更新默认主题纯度测试，扫描默认主题路径中的设计主题名、专属部件名、专属资产名。
7. **发布与验收**：`theme:upgrade -t {theme}` → 店面/预览三态截图 → 默认主题页面无 404、无缺失部件。

### 8.1 三类内容的正确落点

| 内容 | 正确落点 |
|---|---|
| 品牌业务内容（目录、故事、SEO、专属素材） | 设计主题 `{area}/assets/`、`{area}/widgets/`、`{area}/includes/`、模块模板覆盖 |
| 通用部件机制（布局、交互、通用样式）但文件名带主题名 | **语义改名**留在默认主题；同步所有引用与测试 |
| 多主题共用的默认画布/色盘/字体 | 留在默认主题，作为默认身份；不要挪给单一设计主题 |

### 8.2 不允许的迁移方式

- 把设计主题文件复制进默认主题后再改名（默认层仍被污染）。
- 在默认主题保留 `@import` / `<link>` 指向设计主题资产。
- 为迁移方便修改 `theme.css` / `theme.js` 或删除默认机制。
- 迁移后不更新测试/静态映射，留下悬空引用。

## 9. 验收清单

| 项 | 命令/检查 | 通过标准 |
|---|---|---|
| 默认主题纯度 | `vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php app/code/Weline/Theme/test/Unit/ThemeDefaultPurityTest.php` | exit 0，无违禁 token |
| 主题列表 | `php bin/w theme:listing` | 目标设计主题存在且 parent 正确 |
| 发布 | `php bin/w theme:upgrade -t {theme}` | exit 0；设计区 ns 资源可访问 |
| 布局/部件契约 | `php bin/w frontend:check-theme-layout-widgets` | exit 0 |
| section 身份 | `php bin/w frontend:check-section-code` | exit 0 |
| PHP 语法 | `php -l <changed.php>` | `No syntax errors detected` |
| 视觉 | 店面 + 预览三态截图 | 版心/画布/焦点/亮暗色不破；无 404 |

**静态自查**

```bash
# 默认主题不得出现具体设计主题名（示例：把 {theme} 换成实际主题名）
rg -n -i '\{theme\}|\{brand\}' app/code/Weline/Theme/view/theme app/code/Weline/Theme/view/statics \
  --glob '!**/test/**' --glob '!**/doc/**' --glob '!**/view/tpl/**'

# 设计主题不得同 key 覆盖全局入口
rg -n 'assets/(css/theme\.css|js/theme\.js)' app/design/{Vendor}/{theme}

# 默认层禁 !important（新增）
rg -n '!important' app/code/Weline/Theme/view/theme/{area}/assets/css/theme.css
```

## 10. 存量与例外

- 默认主题中既有的厂商命名皮肤、历史色盘、auth 画布素材，按**独立机械改名/迁移波次**处理；本规范只约束新增与正在迁移的文件。
- `view/tpl/`、`generated/`、`pub/static` 是产物，不是源；禁止手改。
- 设计主题的 `theme/{area}/...`、`view/theme/{area}/...` 兼容路径是继承/发布的正常落点，不是“多拷贝隐患”；禁止擅自删除一侧。
- 本规范不替代 `hard_constraints`、MCP 主题硬规则与仓库 `AGENTS.md`。
