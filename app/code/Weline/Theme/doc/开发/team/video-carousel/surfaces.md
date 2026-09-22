# surfaces — video-carousel（架构预起草 · 可定稿）

> 席位：架构师 | slug: `video-carousel`  
> 状态：对齐冻结会预起草；技术方案会可微调命名，**不得砍能力 / 不得改已冻 UC 意图**  
> 依据：`spec/video-carousel.md` + `meetings/立项-领域探查.md`

## 意图

在 **Weline_Theme**（+ 既有 Widget / Product）内落地店面「多视频轮播 + 项级关联商品 dialog 多卡」；**不新建** `Video` 业务模块 / Model / 后台 CRUD。

## 冻结表面

### S1 — 部件登记与首页默认嵌件（Theme）

| 项 | 值 |
|----|-----|
| 机制 | Theme `widget.php` 路径登记 + 模板 `@widget.*`；layout Hook else `<w:widget>` |
| 新建 | `widgets/video/video-carousel/default.phtml`；`code=video-carousel`；`type=video`；`area=frontend` |
| 保留 | `video-player` 继续可嵌其它槽；本需求仅换 `homepage-videos` 默认 |
| 默认槽 | Theme `layouts/homepage/default.phtml` **与** `app/design/Weline/hanfu/.../homepage/default.phtml` 同步：`accept` 增 `video-carousel`；else 默认 `name=video-carousel` |
| 禁止 | `default_injections` JSON 绕过；单改一端 layout 导致 default/hanfu 漂移 |

### S2 — ParamSchema 数组项（Theme + Widget 展开链）

| 项 | 值 |
|----|-----|
| 机制 | `Theme/Ui/ParamSchema/video_carousel_items.php` → `base_type=array` + `item_schema` + `sortable` + 合理 `max_items` |
| 登记 | 仿 `hero-slider`/`banner_items`：`widget.php` 或 `@param` 覆写语义 `type=video_carousel_items` |
| 项字段最小集 | `video_type` / `video_url` / `embed_code` / `poster` / `author` / `description`（或 `summary`）/ `product_ids`；可选 `title` |
| 扫描 | `ParamSchemaScanner` → `generated/param_schemas.php`；契约测断言 schema 存在 |

### S3 — ArrayType 项内 `product_picker`（Widget · 缺口扩展）

| 项 | 值 |
|----|-----|
| 机制 | `Weline\Widget\Ui\ParamType\ArrayType::renderItemField` 委托已注册 `ProductPickerType`（或等价 HTML） |
| 值协议 | 与顶层 `product_picker` 对齐（逗号分隔 ID）；写入数组项 JSON / hidden 同步 |
| 编辑器入口 | 数组 HTML **唯一**走 ArrayType（media-item-fields 决议）；禁止 fallback 文本框丢选品 |
| 附带 | `Weline.Widget.Params` 克隆/序列化兼容 picker DOM；嵌套 dialog z-index 回归 |
| 禁止 | 在 Theme 内平行发明第二套数组项渲染器 |

### S4 — 嵌入解析与 CSP（Theme）

| 项 | 值 |
|----|-----|
| 机制 | **同一** `Weline\Theme\Helper\VideoEmbedResolver` + `ThemeVideoEmbedCsp` |
| 扩 bilibili | `resolveBilibiliId`（或等价）+ 受信 `player.bilibili.com`（精确主机清单见技术方案草案） |
| 同步点 | `trustedEmbedHosts()` / 模板 sanitize 白名单 / CSP `frame-src`·`img-src`·`connect-src` **三处同扩** |
| 平台枚举 | 项级 `youtube` / `vimeo` / `bilibili` / `self` / `embed` |
| 禁止 | video-carousel 私有第二套 iframe 解析；不信任源输出危险脚本 |

### S5 — Catalog 按 ID 取卡（Product · 公共 API）

| 项 | 值 |
|----|-----|
| 机制 | `StorefrontProductWidgetCatalog` 新增按 ID 列表取卡（建议名 `cardsByIds`） |
| 内部 | `StorefrontCatalogViewService::publishedOffersForProductIds` + 既有 `mapOffer` / review 聚合 |
| 策略 | **尊重传入 ID 顺序**；显式选品 **勿**再强制 HF-* 过滤丢品 |
| 消费者 | `video-carousel` 主路径；可选后续收口 featured/`product_ids`（本需求不 silently 改旧部件行为，除非 contracts 写明） |
| 禁止 | Theme 模板跨模块 `new` Product Model / Repository |

### S6 — 关联商品 dialog 多卡（店面 UI）

| 项 | 值 |
|----|-----|
| 机制 | 部件自有（或共享）`<dialog class="w-dialog" data-w-component="dialog">` + `Weline.UI.dialog.open/close` |
| 内容 | 多张 `<w:product:card>` 网格；密度对齐货架惯例 |
| 读品时机 | 默认 **SSR 预渲染**当前/各项关联卡进 dialog DOM（零点击 IO）；若查询席冻结异步口，再允许 BinQuery——**禁止模板原生 fetch** |
| 空态 | `product_ids` 空或解析无品 → 隐藏 CTA **或** dialog 诚实空态；禁 `alert`/`confirm` |
| 禁止 | 复用 Compare quickview **单品内容布局**；私造 modal |

### S7 — 轮播 chrome（待组件协商）

| 项 | 值 |
|----|-----|
| 意图 | ≥2 项可观察切换（下一张 / 指示点 / 等价）；单项目可隐藏控件 |
| 落点 | **优先** Theme 既有 carousel / slider chrome；不足则 `component-negotiate` 后再扩 |
| 禁止 | 架构席单方面冻结平行浮层或平行轮播体系 |

## 调用链（店面主路径）

```text
homepage layout Hook else
  → <w:widget type=video name=video-carousel>
      → ParamSchema video_carousel_items（编辑器 ArrayType + product_picker）
      → VideoEmbedResolver（含 bilibili）+ sanitize / CSP
      → 轮播 chrome（当前项：媒体 + 作者 + 简介 + CTA）
      → CTA → Weline.UI.dialog
          → StorefrontProductWidgetCatalog::cardsByIds（或等价）
          → <w:product:card>×N
```

## anti-patterns

- 新建 `Weline_Video`（或同类）模块 / Model / ACL 后台
- `default_injections` 作为 homepage 默认注入通道
- ArrayType 未扩却把 `product_picker` 当文本框「先上线」
- featured 式「声明了 product_ids 却仍 `cards($limit)`」落在本部件
- 私造 modal / 手写平行商品卡 DOM / 模板 `fetch` 打业务 Controller
- 删除或改名 `video-player`
