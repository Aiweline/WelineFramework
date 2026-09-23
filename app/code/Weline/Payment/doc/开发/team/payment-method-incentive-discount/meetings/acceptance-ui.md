# 审查开发成果 · Team:UI: — payment-method-incentive-discount

| 项 | 值 |
| --- | --- |
| seat | `Team:UI:` |
| 波次 | construction 审查 · `ui_prototype_gate_before_test` |
| 日期 | 2026-09-22 |
| 权威 | `contracts.md` UI 门禁；`meetings/UI-align.md`；`meetings/前端-construction.md` |
| 禁改 | SESSION；本席不改生产码 |
| **verdict** | **pass** |

---

## 证据范围与局限

| 证据 | 状态 |
|------|------|
| 读 contracts / UI-align / 前端-construction | ✅ |
| 静态对照 `CheckoutHtmlRenderer` + `index.phtml`（结构/CSS/JS） | ✅ |
| 契约测断言（徽章 / 摘要 row / 禁 JS 拼文案） | ✅（前端纪要 14/14；本席复读断言源） |
| 宿主 Browser 真渲染（配置激励 + 有货结账） | ❌ **不可用**（`browser_navigate` / 新建 tab 后 view 丢失；`curl` checkout 二次下载超时） |
| 像素级对比度实测（WCAG AA 计算） | ❌ 未做（无稳定 paint）；依赖 Theme Token 语义色 |

**局限声明**：本过签为**静态结构 + 样式契约审查**，不能代替 Team:测试: 真 Browser UC（ACC-GATE-1/2）。激励徽章在「后台已配 + 列表 enrich」下的可见像素，须测试席补证。

---

## 对照清单（UI-align / contracts）

| 门禁 | 证据 | 结论 |
|------|------|------|
| **L0** 应付=唯一价格真相；权重最高 | `.weline-checkout__grand` `font-size:18px; font-weight:700`；仅一个 `data-grand-total`；无第二套划线应付 | pass |
| **L1** 方式名/图标主位 | `<strong>` 方式名 + logo；激励进 `payment-title-meta`，不抢名称位 | pass |
| **L2** 「减 X」次级徽章 | `.weline-checkout__payment-incentive`：`font-size: var(--font-size-sm,12px)`；色 `var(--color-success, var(--color-primary))`；选中仅加粗，无整行海报底 | pass |
| **L3** 摘要「支付方式优惠」与券分列 | 独立 `data-checkout-payment-incentive-row`；券仍 `data-checkout-discount-row`；金额 `'-' + money(incentive)` | pass |
| **L4** 不可用/未配无误导「可减」 | Renderer：`!incentive_available \|\| savings≤0` → 不输出徽章；Provider 归一化同逻辑 | pass |
| **禁划线价 / 伪原价 / 贬损** | 激励相关 CSS **无** `line-through`；无无激励行「+¥」/「更贵」；摘要无双应付 | pass |
| **切换无先高后低** | `change` → 先 `renderTotals()`（列表扁字段）再 `schedulePaymentIncentiveReconcile`；注释明确禁先高后低 | pass |
| **Token** | 激励色/字重走 `--color-*` / `--font-*`；未新增 Payment 私有促销色板；`work_mode=Checkout 运行时`（前端已声明） | pass |
| **SSR 徽章 / 禁 JS createElement 造文案** | Renderer 输出 `data-payment-incentive` + `incentive_display`；契约测扫描 | pass |

### 非本 feature 的既有划线（不记 fail）

`.weline-checkout__item-price-was { text-decoration: line-through }` 属**商品行原价/现价**既有样式，**不**用于支付方式激励行。UI-align §3 针对支付激励叙事；本席不因此否决本波。

---

## 残差（不阻塞本席 pass；交下游）

1. **真 Browser**：测试席须在禁缓存 + 抹 webdriver 下验 L2 徽章可见、选中后 L3 行出现、L0 同步。
2. **对比度 AA**：`--color-success` 在深/浅主题下的实测留给主题/测试；静态无法算对比度。
3. **原型席**：ACC-GATE-3 须 UI+**原型**双过签；本文件仅 UI 席。

---

## 可执行返工项

**无。**（verdict=pass，无 fail 返工清单）

若测试席 Browser 发现徽章压过应付、出现激励划线、或未配仍显示「可减」，再开返工票指向：`CheckoutHtmlRenderer::renderPaymentMethodOptions` / `index.phtml` 激励 CSS+`renderTotals`。

---

## result

`closed` · **verdict=pass** · `notify_pm: true`

**@项目经理：本席已交付/上报，请检查并更新 SESSION**（禁本席改 SESSION）。UI 过签通过，可与原型过签合流后放行 Team:测试: 真 Browser。

### paths_changed

- `meetings/acceptance-ui.md`（本文件）
- `channel/construction.md`（stance）
