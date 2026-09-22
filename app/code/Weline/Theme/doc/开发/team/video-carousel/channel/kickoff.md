# channel: kickoff

## msg-1 | 2026-09-21T22:10:00+08:00 | from:项目经理 | to:* | thread:kickoff | kind:handoff
agent_id: parent
body:
立项波启动。计划已批准：新部件 video-carousel；保留 video-player；homepage-videos 换轮播；平台 youtube/vimeo/bilibili/self/embed；项内 product_ids=product_picker（须扩 ArrayType）；关联商品用 Weline.UI.dialog 多卡。
请需求分析写 ready-for-plan 规格到 app/code/Weline/Theme/doc/开发/spec/video-carousel.md。
请领域探查只读确认扩展点与冲突，回报框架映射。
---

## msg-2 | 2026-09-21T22:12:00+08:00 | from:领域探查 | to:项目经理,架构师,需求分析 | thread:kickoff | kind:result
agent_id: e8c50b75-7e24-40d1-ba6b-0957b4575153
body:
result=closed。纪要：app/code/Weline/Theme/doc/开发/team/video-carousel/meetings/立项-领域探查.md
框架映射要点：复用 video-player（保留）+ VideoEmbedResolver + ParamSchema *_items 模式 + Weline.UI.dialog + w:product:card + widget.php 路径登记；须扩展 ArrayType 项内 product_picker（缺口已确认）、bilibili→Resolver/CSP/sanitize、Catalog 按 ID 取卡公共 API（现无私有 cardsByIds，底层有 publishedOffersForProductIds）、homepage-videos accept/默认部件（default+hanfu 双改）；部分复用 Compare quickview（仅 dialog 壳，勿套单品内容）。无重大架构矛盾，不 escalate。
---

## msg-2 | 2026-09-21T22:18:00+08:00 | from:需求分析 | to:* | thread:kickoff | kind:handoff
agent_id: df0bb74e-630b-4bdd-83c2-a87de4cccebb
body:
规格已落盘并升为 ready-for-plan。

- 路径：`app/code/Weline/Theme/doc/开发/spec/video-carousel.md`
- 用户故事 ×3；EARS ×7；UC-1…UC-4（基址 `https://p05113ef3.test.weline.com:9555/`，步骤可喂 Playwright）
- 框架映射：复用 `VideoEmbedResolver` / `product_picker` / `ArrayType`（须扩项内 product_picker）/ `Weline.UI.dialog` / `<w:product:card>`；保留 `video-player`；homepage-videos 默认嵌件改 `video-carousel`（Theme default + hanfu）；禁止 Video 模块与 default_injections JSON
- 验收意图：`video-carousel-plan-suite` + WB-OP
- 本席未改业务 PHP/模板/CSS
- result=closed；请项目经理在领域探查 closed 后进入对齐冻结 / Plan Mode
---
