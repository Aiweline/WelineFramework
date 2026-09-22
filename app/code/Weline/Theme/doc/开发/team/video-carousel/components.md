# components — video-carousel（架构预起草 · 可定稿）

> 席位：架构师 | slug: `video-carousel`  
> 状态：复用清单预冻结；**轮播 chrome / dialog 密度**若现有组件不足 → 须 `channel/component-negotiate.md`（原型∥UI±主题）后再扩  
> UI in_scope：是

## 意图

店面首页视频区：**轮播多视频项** + 作者/简介 +「查看关联商品」→ 主题 dialog 内**多商品卡**。编辑器侧：数组项配置 + 项内选品。

## 复用清单（默认只组合，禁止平行再造）

| ID | 组件 / 机制 | 拥有 | 本功能用法 | 判定 |
|----|-------------|------|------------|------|
| C1 | `video-player` 部件 | Theme | **保留**作参考（解析/空态/预览）；首页默认改挂轮播，不删不替全局 | 复用参考 |
| C2 | `VideoEmbedResolver` + sanitize | Theme | 项内嵌入 URL/ID 唯一解析入口；扩 bilibili | 复用 + 扩 |
| C3 | `ThemeVideoEmbedCsp` | Theme | frame/img/connect 受信域与 Resolver 同步 | 复用 + 扩 |
| C4 | ParamSchema `*_items` + `ArrayType` | Widget/Theme | 新建 `video_carousel_items`；数组外壳 | 复用模式 |
| C5 | `ProductPickerType` / `product_picker` | Product | 项内 `product_ids`；经 ArrayType 委托 | 复用 + ArrayType 扩 |
| C6 | `Weline.UI.dialog` / `w-dialog` | Theme UI | 关联商品浮层 open/close | **强制复用** |
| C7 | `<w:product:card>` | Product Taglib | dialog 内多卡；禁手写卡 DOM | **强制复用** |
| C8 | Compare `#weline-product-quickview-dialog` | Compare | 仅对照 data-w / open·close 约定 | **部分复用**（勿套单品内容） |
| C9 | `StorefrontProductWidgetCatalog` 卡片 shape | Product | 新 `cardsByIds` 输出同形卡数据供 Taglib | 复用 shape + API 扩 |
| C10 | homepage slot chrome / section | Theme+hanfu | `#homepage-videos` 槽与布局位置不变 | 复用槽 |
| C11 | Theme 既有 carousel / slider 控件 | Theme | 项切换 UI（下一张/点） | **优先复用**；不足→协商 |

## 新建（本需求允许的「部件级」组合面，非平行体系）

| ID | 名称 | 说明 |
|----|------|------|
| N1 | `video-carousel` 部件根 | `data-site-block="video-carousel"`（对齐 video-player 约定）；含媒体区、文案区、CTA、轮播控件挂载点 |
| N2 | 关联商品 dialog（部件作用域） | 独立 `id`（勿与 quickview 撞车）；body = 商品卡网格 + 空态 |
| N3 | `video_carousel_items` schema | 配置面，非可视组件 |

## 缺口（须协商或扩展点席确认）

1. **轮播 chrome**：是否有可直接挂的 Theme carousel token / JS；若无，原型∥UI 出最小控件规格后再让主题落 CSS/JS（禁止前端私造第二套浮层体系）。
2. **dialog 多卡密度**：对齐货架 `w-product-card` 属性（加购/评分是否显）；Compare quickview **不是**内容模板。
3. **编辑器嵌套 dialog**：项内 picker 已用 `Weline.UI.dialog`，与侧栏 z-index 需回归——属扩展/前端关注，不另造 picker。

## anti-patterns

- 私造 modal / 原生 `alert`·`confirm`
- 手写平行商品卡网格（绕过 `<w:product:card>`）
- 复制一套 iframe 嵌入管道绕过 Resolver/CSP
- 把 Compare quickview 单品 HTML 当多卡列表壳
- 未协商就新增全站级 carousel 组件库

## 组件协商触发条件

若对齐冻结后主题/前端确认 **无**可挂既有 carousel chrome，或 dialog 多卡需新 `w-*` token：**必须**开 `component-negotiate` 线程；未决议禁止施工新控件。
