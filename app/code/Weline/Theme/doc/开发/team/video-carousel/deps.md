# deps · video-carousel（依赖边 · 对齐冻结）

- slug: `video-carousel`
- 冻结时间: 2026-09-21T22:30:00+08:00
- 主持席: 测试
- 状态: **frozen**
- 配套: `contracts.md` + `meetings/对齐冻结.md`（UC-1…UC-4）

> 唤醒规则：仅当依赖边已满足才开工；上游 `closed` 才 resume 下游。禁止全员空等终点。

---

## 建议施工顺序（权威）

```text
1. ArrayType（项内 product_picker）
2. Resolver/CSP + ParamSchema + 部件模板（可与 1 尾部重叠；模板可先占位，picker 未好则编辑器造数 defer）
3. Catalog cardsByIds
4. homepage 双 layout 默认嵌件（可与 2 登记后并行，不依赖 Catalog）
5. 前端 JS（轮播 + dialog）—— wait：部件 DOM 契约 +（UC-3）cards 数据通路
6. 合同测（随各上游 closed 增量变绿；骨架先红）
7. Browser / e2e plan-suite（wait：前端 + layout + 造数就绪）
```

---

## 节点定义

| id | 席位 / 轨 | 交付摘要 | 可先开干？ |
|---|---|---|---|
| D1 | 后端 · Widget | ArrayType 项内 `product_picker` + 编辑器数组不退化 | **是**（无上游业务依赖） |
| D2 | 后端 · Theme | `VideoEmbedResolver` + `ThemeVideoEmbedCsp` + sanitize 扩 bilibili | **是**（可与 D1 并行） |
| D3 | 后端 · Theme | ParamSchema `video_carousel_items` + `widget.php` 登记 | wait D1（项内 picker 才有完整编辑体验；schema 文件可先落、picker 分支 unblock 后验收） |
| D4 | 主题 · Theme | 部件模板 `video-carousel`（根选择器、项 DOM、dialog 壳占位） | wait D2（嵌入安全）；卡片数据可先 mock/空，**正式多卡** wait D5 |
| D5 | 后端 · Product / 查询 | `StorefrontProductWidgetCatalog::cardsByIds`（或等价） | **是**（可与 D1/D2 并行） |
| D6 | 主题 | homepage default + hanfu 双 layout 换默认嵌件 + accept | wait D3 登记存在（name 可引用）；**不** wait 前端 |
| D7 | 前端 | 轮播切换 JS | wait D4 DOM 契约 |
| D8 | 前端 | CTA → `Weline.UI.dialog` + 多卡/空态 | wait D4 + D5（或 SSR 已进 DOM） |
| D9 | 主题 / UI | Token / 视觉 polish | wait D4；可与 D7/D8 尾部并行 |
| D10 | i18n | 中英 CSV + collect | wait 文案源串落入模板/schema（D3/D4） |
| D11 | 测试 · 合同测 | homepage / schema / ArrayType / Resolver / CSP | 骨架：**对齐冻结后立即**；执行：各对应 Di closed |
| D12 | 测试 · e2e + Browser | `video-carousel-plan-suite` UC-1…UC-4 | wait D6+D7+D8（+ 造数：D1 编辑器或默认种子） |

---

## 依赖边（谁 wait 谁）

```text
D1 ArrayType ─────────────────────┐
                                  ├→ D3 ParamSchema 完整可配 → D4 部件（编辑器路径）
D2 Resolver/CSP ──────────────────┤
                                  └→ D4 部件嵌入安全

D5 Catalog cardsByIds ────────────→ D4/D8 多卡数据

D3 + D2 ──→ D4 部件模板 ──→ D7 轮播 JS
                       └──→ D8 dialog JS
D3 ──→ D6 双 layout
D4 + D6 ──→ UC-1 可测面
D4 + D7 ──→ UC-2 可测面
D4 + D5 + D8 ──→ UC-3 / UC-4 可测面
D10 ──→ 文案抽检（不挡 UC-1 结构断言）
D11 随 Di 增量
D12 wait (D6 ∧ D7 ∧ D8 ∧ 造数就绪)
```

---

## 并行波建议（项目经理唤醒用）

| 波 | 可同时拉起 | 仍 wait |
|---|---|---|
| 施工-1 | D1、D2、D5 | — |
| 施工-2 | D3（D1 基本可用后）、D4 起步（D2 后） | D8 多卡 |
| 施工-3 | D6（D3 登记后）、D7（D4 DOM 后）、D9 起步 | D12 |
| 施工-4 | D8（D4+D5）、D10 | — |
| 测试执行 | D11 对应章变绿；D12 plan-suite + WB-OP | 上游未 closed 禁止改已冻 UC |

---

## 禁止反流

- 下游不得私改上游已冻接口签名 / 选择器 / UC 步骤而不回对齐冻结会。
- `featured-products` 等历史「忽略 product_ids」债务：**本需求不强制 silently 改行为**；新 `cardsByIds` 供 video-carousel 使用即可（合约 §4）。
- 技术方案会可细化 how，**不得**把 UC-3「dialog 内 ≥2 卡」降成壳层冒烟。
