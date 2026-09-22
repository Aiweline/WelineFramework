---
status: ready-for-plan
work_kind: feature
feature_slug: video-carousel
module: Weline_Theme
fe_be_scope: frontend+widget+param-schema
team_slug: video-carousel
updated: 2026-09-21
---

# 视频轮播部件（video-carousel）

## 澄清记录

本需求立项前已由项目经理拍板，**不再开选项**。下列为已确认答复，写入规格供对齐冻结与计划引用。

| # | 议题 | 已确认 |
|---|------|--------|
| Q1 | 部件策略 | **新建**部件 `video-carousel`（`type=video`）；**保留**既有 `video-player`，不替换、不删除 |
| Q2 | 首页默认嵌件 | 槽 `homepage-videos` 的 Hook else 默认嵌件改为 `video-carousel`；覆盖 **Theme 默认 homepage layout** 与 **hanfu homepage layout** |
| Q3 | 视频平台 | 项级 `video_type` 支持：`youtube` / `vimeo` / `bilibili` / `self` / `embed` |
| Q4 | 配置模型 | ParamSchema `video_carousel_items`（`base_type=array`）；项内 `product_ids` 使用 `type=product_picker`；**须扩展** Widget `ArrayType` 项字段以支持 `product_picker`（当前无该分支会落成普通文本） |
| Q5 | 前台交互 | 轮播展示视频 + 作者 + 简介；CTA「查看关联商品」→ `Weline.UI.dialog`（`w-dialog`）内多商品卡 |
| Q6 | 模块边界 | **不新建** `Video` 业务模块；能力落在 Theme（+ 既有 Widget / Product 选品与卡片） |
| Q7 | 默认注入方式 | **禁止** `default_injections` JSON 绕过；仅改 layout 内 `<w:widget …>` 嵌件（与现网 `video-player` 写法同级） |

## 目标 / 非目标

### 目标

- 主题编辑器可配置**多条**视频项（平台、地址/嵌入、作者、简介、关联商品）。
- 店面以**轮播**呈现视频区：当前项可见播放器/嵌入、作者、简介；可切换项。
- 访客可对当前项点击「查看关联商品」，弹出主题对话框并展示**多张**商品卡。
- 首页 `homepage-videos` 默认即用 `video-carousel`（default + hanfu）。
- 嵌入解析、选品、对话框、商品卡优先复用框架既有能力（见「框架映射要点」）。

### 非目标

- 不删除、不改名 `video-player`；其它槽位已嵌的 `video-player` 可继续工作。
- 不新建独立 `Weline_Video`（或同类）模块 / Model / 后台 CRUD。
- 不引入 `default_injections` JSON 或平行默认注入通道。
- 不在本需求内做视频上传服务端编码转码流水线（`self` 仅消费已配置的可播 URL / 媒体约定）。
- 不把关联商品详情页重做进弹层；弹层内为卡片列表，跳转走既有商品卡行为。
- 不把本规格写成具体类补丁步骤（how 留给架构 / 计划）。

## 角色

| 角色 | 诉求 |
|------|------|
| 店面访客 | 浏览首页视频轮播、了解作者与简介、查看关联商品 |
| 主题编辑者 | 在可视化编辑器增删排序视频项、选平台与地址、选关联商品、配置可译文案 |
| 主题/前端实现者 | 在 Theme 部件 + Widget ParamSchema/ArrayType 扩展点内落地，不跨模块硬编码 |

## 用户故事

1. **作为**店面访客，**我希望**在首页视频区以轮播方式浏览多条穿搭/品牌视频，并看到作者与简介，**以便**快速建立信任与灵感，而无需离开首页。
2. **作为**店面访客，**我希望**点击「查看关联商品」后在主题对话框中看到该视频关联的多件商品卡，**以便**一键继续浏览或加购相关商品。
3. **作为**主题编辑者，**我希望**用数组项配置多平台视频与 `product_picker` 选品，**以便**运营可自助维护首页视频内容而无需改代码。

## EARS（验收标准）

1. WHEN 首页 `homepage-videos` 以默认 layout 嵌件渲染（Theme default 与 hanfu），系统 SHALL 输出 `type=video` 且 `name=video-carousel` 的部件（而非默认 `video-player`）。
2. WHEN 部件配置含 ≥2 条有效 `video_carousel_items`，系统 SHALL 提供可观察的轮播切换，使访客能看到不同项的视频区、作者与简介。
3. WHEN 当前项配置了非空 `product_ids` 且访客点击「查看关联商品」，系统 SHALL 打开 `Weline.UI.dialog`（`data-w-component=dialog` / `w-dialog`），并在对话框内展示对应商品的多张 `<w:product:card>`（或等价 Product 卡片 Taglib 输出）。
4. IF 当前项 `product_ids` 为空或解析后无有效商品，THEN 系统 SHALL 不展示误导性的成功商品列表（隐藏 CTA，或打开后诚实空态；禁止原生 `alert`/`confirm`）。
5. WHEN 项的 `video_type` 为 `youtube` / `vimeo` / `bilibili` / `self` / `embed` 且源有效，系统 SHALL 按类型渲染可播/可嵌内容；无效或不信任源 SHALL 不输出危险脚本，并呈现诚实空态或占位。
6. IF 编辑器保存含 `product_picker` 的数组项，THEN Widget `ArrayType` SHALL 正确渲染并回填 `product_ids`（不得退化为无法选品的纯文本框导致丢配置）。
7. WHILE `video-player` 仍注册在 theme widgets，系统 SHALL 保持其可独立嵌入其它槽位；本需求默认仅替换 `homepage-videos` 的默认嵌件。

## 隐形需求摘要

- 浮层必须走 `Weline.UI.dialog` / `w-dialog`（`weline_ui_floating_primitives`）；禁止私造 modal / 原生对话框。
- 店面业务 IO 若需异步取品，须走 BinQuery / 既有 Query 契约，禁止模板内原生 `fetch` 直打业务 Controller。
- 可译文案（区标题、作者、简介、CTA 等按 ParamSchema `i18n`）源串简中；模块 CSV 中英；用户若提「翻译」则默认站全语种（本立项未要求全语种落盘时可先中英）。
- 嵌入域须延续 / 扩展 Theme CSP 与 `VideoEmbedResolver` 信任主机策略（含 bilibili 所需主机）。
- 商品卡统一 `<w:product:card>`，禁止手写平行商品卡 DOM。
- 契约测试：现有 `ThemeHanfuHomepageDefaultsContractTest` 等断言 `video-player` 默认嵌件处须同步改为 `video-carousel`。
- 验收基址：`https://p05113ef3.test.weline.com:9555/`（主链交付时另遵本机 Host 门禁；本规格用例步骤固定此 URL 供 Playwright）。

## 框架映射要点（复用，不平行发明）

| 能力 | 复用指向 | 备注 |
|------|----------|------|
| 嵌入 URL / ID 解析与安全 URL | `Weline\Theme\Helper\VideoEmbedResolver` | 现有 YouTube/Vimeo；本需求扩展 bilibili（及信任主机/CSP 对齐） |
| 单视频部件参考实现 | `widgets/video/video-player` | **保留**；轮播新部件可对照其解析/空态/预览模式，禁止复制成第二套不安全 iframe 管道 |
| 数组参数外壳 | Widget `ArrayType` + ParamSchema `*_items.php` | 新增 `Theme/Ui/ParamSchema/video_carousel_items.php` |
| 项内选品 | Product `product_picker` / `ProductPickerType` | **须扩** `ArrayType::renderItemField`（或等价委托已注册 ParamType）以支持项内 `product_picker` |
| 关联商品弹层 | `Weline.UI.dialog` + `w-dialog` | 对齐选品器/货架已有 dialog 用法 |
| 弹层内商品展示 | `<w:product:card>` | 多卡列表；密度/加购等属性对齐货架惯例 |
| 部件注册 | Theme `widget.php` + `type=video` | `code=video-carousel`；`accept` 槽增加新 name |
| 首页默认嵌件 | layout Hook else 内 `<w:widget>` | **禁止** `default_injections` JSON；改 `view/theme/.../homepage/default.phtml` 与 `app/design/Weline/hanfu/.../homepage/default.phtml` |
| 读品 | Catalog / Product 既有列表或 QueryProvider | 跨模块禁直调 Model；有列表契约则走查询席冻结的口 |

## 数据与配置（what）

### 部件

- `code`: `video-carousel`
- `type`: `video`
- `area`: `frontend`
- 建议槽兼容：与 `video-player` 同级进入 `homepage-videos` 的 `accept`（含 `video` / 新 name）

### ParamSchema `video_carousel_items`

语义 type → `base_type=array`，`sortable=true`，合理 `max_items`。

项字段（最小集，计划可微调命名但不得砍能力）：

| 字段 | 类型意图 | 说明 |
|------|----------|------|
| `video_type` | select | `youtube` / `vimeo` / `bilibili` / `self` / `embed` |
| `video_url` | url | 页面/直链；`i18n=false` |
| `embed_code` | textarea | embed 类型；可兜底裸 URL；`i18n=false` |
| `poster` | media_image | self 等封面；`i18n=false` |
| `author` | string | 作者；可译 |
| `description` / `summary` | textarea | 简介；可译 |
| `product_ids` | **product_picker** | 关联多商品；`i18n=false` |
| （可选）`title` | string | 单项标题；可译 |

部件级可选：区标题、自动播放/静音等（对齐 `video-player` 既有体验，非必须另开选项争论）。

## 用例（喂 Playwright / Browser）

基址（字面一致）：`https://p05113ef3.test.weline.com:9555/`

> **对齐冻结（2026-09-21）**：可执行步骤 / 选择器 / 断言的权威副本在  
> `doc/开发/team/video-carousel/meetings/对齐冻结.md`（UC-1…UC-4）。  
> 配套：`contracts.md`、`deps.md`。改验收意图须回对齐冻结会。下列为冻结摘要（意图未改）。

### UC-1 首页默认视频轮播可见（主成功）

| 字段 | 内容 |
|------|------|
| id | UC-1 |
| 名称 | 首页默认渲染 video-carousel |
| 角色 | 店面访客（匿名） |
| 前置 | 本机站点可访问；default 或当前激活主题 homepage 已含默认 `video-carousel` 嵌件；至少可观察媒体或诚实空态 |
| 主成功步骤 | 1. `goto` `https://p05113ef3.test.weline.com:9555/`（domcontentloaded）<br>2. 滚动 `#homepage-videos` 入视<br>3. 断言 `#homepage-videos [data-site-block="video-carousel"]` attached（timeout≥20s）<br>4. 断言部件内作者/简介/区标题之一可见且非空，**或**诚实空态可见<br>5. 合同：layout 默认嵌件为 `name="video-carousel"`（非该槽唯一默认 `video-player`）<br>6. body 无 Fatal/ParseError/WLS Runtime Error |
| 备选/异常 | 若主题未启用该槽：记环境失败，不改用例意图 |
| 期望结果 | 首页视频区以轮播部件呈现，可观察媒体或诚实空态，无 JS fatal |
| 映射 acceptance | e2e `video-carousel-plan-suite` / WB-OP-首页视频区 |

### UC-2 轮播切换多项

| 字段 | 内容 |
|------|------|
| id | UC-2 |
| 名称 | 多视频项轮播切换 |
| 角色 | 店面访客 |
| 前置 | 编辑器或默认配置使 `video_carousel_items` ≥2 且两项作者/简介可区分；站点已保存并前台可见 |
| 主成功步骤 | 1. 打开 `https://p05113ef3.test.weline.com:9555/`<br>2. 定位 `#homepage-videos [data-site-block="video-carousel"]`<br>3. 记录当前项作者或简介文本 A<br>4. click 下一张控件（选择器见对齐冻结表）<br>5. 断言文本变为可区分 B（B≠A）或幻灯 `data-index`/`aria-current` 变化 |
| 备选/异常 | 仅 1 项时：轮播控件可隐藏；本 UC 标 skip 并依赖编辑器造数用例 |
| 期望结果 | 切换后可见内容变化，无整页刷新强制要求 |
| 映射 acceptance | e2e 轮播交互章 |

### UC-3 查看关联商品对话框（主成功）

| 字段 | 内容 |
|------|------|
| id | UC-3 |
| 名称 | 关联商品 Weline.UI.dialog 多卡 |
| 角色 | 店面访客 |
| 前置 | 当前项 `product_ids` ≥2 且商品前台可售/可见；CTA「查看关联商品」可见 |
| 主成功步骤 | 1. 打开 `https://p05113ef3.test.weline.com:9555/`<br>2. 滚动至视频轮播，确保目标项为当前项<br>3. 点击文案含「查看关联商品」的控件<br>4. 断言打开态 dialog：`[data-w-component="dialog"]` / `w-dialog[open]` 可见<br>5. 断言 dialog 内 `[data-testid="weline-product-card"]` **count ≥ 2**<br>6. 关闭 dialog，断言不可见；无原生 `alert` |
| 备选/异常 | 见 UC-4 |
| 期望结果 | 仅主题 dialog；无 `window.alert`；多卡可见（禁止壳层冒烟替代） |
| 映射 acceptance | e2e 关联商品弹层章；合规抽检商品卡 |

### UC-4 无关联商品时的诚实行为（异常/边界）

| 字段 | 内容 |
|------|------|
| id | UC-4 |
| 名称 | 空 product_ids 不伪造成功列表 |
| 角色 | 店面访客 / 编辑者验证 |
| 前置 | 存在一项 `product_ids` 为空（或全部失效） |
| 主成功步骤 | 1. 打开 `https://p05113ef3.test.weline.com:9555/`（或带预览参数的等价前台页）<br>2. 切换到无关联商品的项<br>3. 断言「查看关联商品」CTA **不可见**，**或**点击后 dialog 内为诚实空态文案且商品卡数为 0<br>4. 断言控制台无未捕获异常；无原生 `alert` |
| 备选/异常 | 无 |
| 期望结果 | 不展示虚假商品网格 |
| 映射 acceptance | e2e 空关联边界章 |

## 将产生的验收意图（计划须挂上）

- `type=e2e` 套件建议 id：`video-carousel-plan-suite`（含 UC-1…UC-4 主路径，禁止仅壳层冒烟替代 UC-3）。
- Browser WB-OP：首页视频区视觉 + dialog 多卡；打开验收 Browser 须禁用缓存。
- UT/契约：homepage 默认嵌件断言、`video_carousel_items` ParamSchema 扫描、`ArrayType`+`product_picker` 合同、`VideoEmbedResolver` bilibili（若扩展）、CSP 信任主机。

## 待架构对齐（非本席拍板实现细节）

- bilibili 解析规则与 CSP 主机清单的精确集合。
- 关联商品数据是 SSR 预渲染进 dialog DOM，还是点击后 BinQuery；须查询席冻结契约。
- 轮播交互是 Theme 既有 carousel chrome 还是轻量自研控件——**组件协商**（原型∥UI∥主题）后再定，禁止单席私造平行浮层。

## 就绪检查

- [x] `status: ready-for-plan`
- [x] ≥1 用户故事 + 每故事可追溯 EARS
- [x] EARS ≥2（本文件 7 条）
- [x] UC-1…UC-4（含主成功与空关联边界；步骤可喂 Playwright）
- [x] **对齐冻结**：`contracts.md` + `deps.md` + `meetings/对齐冻结.md`（可执行 UC）
- [x] 非目标明确
- [x] 已点名 e2e / WB-OP 验收意图
- [x] 框架映射要点已写（复用指向，非补丁步骤清单）
- [x] 澄清无未关闭选项（立项已拍板）
- [x] 红灯骨架草稿：`test/e2e/frontend/video-carousel-plan-suite.spec.js`

## 下一步（给项目经理）

1. ~~对齐冻结~~ **已完成**（`contracts.md` + `deps.md` + `meetings/对齐冻结.md`；通道 stance closed）。
2. 进入技术方案会（架构师 + 扩展点）；按 `deps.md` 唤醒施工，禁止未冻三者写码。
3. 测试红灯骨架已落：`test/e2e/frontend/video-carousel-plan-suite.spec.js`。
