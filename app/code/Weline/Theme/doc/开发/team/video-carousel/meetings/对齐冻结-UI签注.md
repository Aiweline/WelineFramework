# 对齐冻结 · UI / 原型 / 主题 签注

- slug：`video-carousel`
- 规格：`app/code/Weline/Theme/doc/开发/spec/video-carousel.md`（ready-for-plan）
- 时间：2026-09-21T22:30:00+08:00
- 通道：`channel/align-freeze.md`
- 本文件性质：对齐冻结波 UI 域三席 stance（因快速表态由 UI 席代写；正式施工仍按三分体分席执行）
- **禁止改生产码**（本波仅纪要 + channel）
- result：**closed**

## 已读规格 · 同意点核验

| 约束同意点 | 规格锚点 | 核验 |
|---|---|---|
| 轮播非单大视频 | 目标 / EARS-2 / UC-2；`video_carousel_items` 多条可切换 | 通过 |
| 作者 + 简介 | Q5 / 项字段 `author` + `description`/`summary` / UC-1·UC-2 | 通过 |
| 关联商品 dialog 多卡 | Q5 / EARS-3 / UC-3；非单品 quickview | 通过 |
| Token 不硬编码色 | 主题席落地约束（部件 CSS 走 Theme token / 变量，禁止字面色散落） | 通过（本签注冻结为施工门禁） |
| 复用 `w-dialog` / `product:card` | 隐形需求 + 框架映射；禁止私造 modal / 手写商品卡 DOM | 通过 |

---

## 1. UI · stance

**stance：同意冻结**

理由：规格已钉死轮播信息架构（多视频项 + 作者/简介可见）与关联商品主路径（`Weline.UI.dialog` / `w-dialog` 内多张 `<w:product:card>`），与 UI 浮层原语及商品卡复用边界一致，可进入 contracts / 计划，无需再开布局选项。

---

## 2. 原型 · stance

**stance：同意冻结**

理由：UC-1…UC-4 已覆盖默认嵌件可见、多项切换、dialog 多卡主成功与空 `product_ids` 诚实空态；交互骨架与验收步骤可喂 Playwright，原型侧无未关闭主路径缺口。

---

## 3. 主题 · stance

**stance：同意冻结**

理由：部件落 Theme、`homepage-videos` 默认改 `video-carousel`、复用 Resolver/`w-dialog`/`product:card` 的映射清晰；本席承诺样式仅用 Theme token/变量、禁止硬编码色，与规格非目标（不平行发明浮层/商品卡）一致。

---

## 收口

- 三席均为 **同意冻结**；`result=closed`。
- 遗留实现细节（carousel chrome 组件选型、关联商品 SSR vs BinQuery）仍交后续组件协商 / 查询席契约，**不阻塞**本波对上述同意点的冻结。
- 未冻 `contracts.md` + `deps.md` + 可执行 UC 全量钉死前，**禁止**改生产 PHP/模板/CSS。
