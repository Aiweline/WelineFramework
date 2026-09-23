# acceptance-prototype.md — payment-method-incentive-discount

| 项 | 值 |
|----|-----|
| 席位 | Team:原型: |
| 波次 | 审查开发成果（`ui_prototype_gate_before_test`） |
| 日期 | 2026-09-22 |
| 对照 | `meetings/原型-align.md` · `components.md` · 前端施工（`Checkout` 1.5.41） |
| 审查面 | 列表激励槽 · 摘要分列 · 切换重算 |
| **verdict** | **pass** |
| 真 Browser | 本席未开（门禁后交测试）；本审为对照对齐稿的代码/契约证据审 |

---

## 总判

结账「减 X」信息架构已按冻结意图落到**既有结账壳**（`weline-checkout__option--payment` + `weline-checkout__totals`），未另造平行 SaaS 选卡墙；列表可见激励、摘要与券分列、切换即时重算 + getData 核对均满足原型过签门槛。

---

## 检查表

| # | 对齐要求 | 证据 | 结论 |
|---|----------|------|------|
| 1 | 激励挂既有支付列表壳，禁第二套选卡 UI | 生产结账路径走 `CheckoutHtmlRenderer::renderPaymentMethodOptions` → `weline-checkout__option--payment` + `span.weline-checkout__payment-incentive[data-payment-incentive]`；未引入脱离结账的平行列表 | **pass** |
| 2 | 有激励且可用才渲染；不可用禁误导「可减」 | `incentive_available && savings>0 && display≠''` 才拼徽章；`available=false`/0 不渲染（HtmlRenderer + Provider normalize） | **pass** |
| 3 | 未选中亦可见减免提示 | SSR 对每个可用激励 method 输出徽章（不依赖 `:checked`）；选中仅 CSS 加粗字重 | **pass**（见观察 O1） |
| 4 | 选中态加重 | `:has(input:checked) .weline-checkout__payment-incentive { font-weight: bold }` | **pass** |
| 5 | 摘要独立「支付方式优惠」行，与「优惠」分列 | `data-checkout-payment-incentive-row` + `data-payment-incentive-amount`；在 COD 行后、应付前；`data-checkout-discount-row` 仍只管券/满减 | **pass** |
| 6 | 无激励时摘要行 hidden | `renderTotals`：`incentive≤0` → `hidden`；续付冻结路径亦清零隐藏 | **pass** |
| 7 | 切换即时重算；禁默认「先高后低」误导闪烁 | `paymentBox` `change` → 先 `renderTotals()`（用列表 `incentive_savings_minor`）→ `schedulePaymentIncentiveReconcile` → `getData({payment_method})` 再核对 | **pass** |
| 8 | 应付扣激励；与列表可兑现额同源 | `selectedIncentiveSavingsMajor()` 读扁字段；`payable = … - incentive`；禁前端自算百分比 | **pass** |
| 9 | 消费冻结正式字段名 | `incentive_savings_minor` / `incentive_display` / `incentive_available`；禁 JS `createElement` 拼徽章文案 | **pass** |
| 10 | 激励叙事 / 禁 surcharge UI | 无「他法多付」文案；COD 仍为加价行，激励为负向摘要行 | **pass** |
| 11 | Theme Token，不定私有色板 | 激励色 `var(--color-success, var(--color-primary))` 等 | **pass**（视觉细项交 UI 席） |

---

## 观察（非阻塞 · 不否决）

### O1 · 「可选减」分态文案未落地

- **对齐稿**曾写：未选「可选减 ¥X」、已选「减 ¥X」。
- **冻结 contracts / Quote**：单一 `incentive_display` =「减 %1」/「减 %1%（约 %2）」；未选与已选同串，靠字重分态。
- **信任目标仍满足**：未选中卡片已展示可兑现「减 X」，避免选中后才惊喜变价。
- 翻译席已记「可选减」未单开源串。若运营坚持分态话术，另开小改波由前端+翻译补，**不作为本波 fail**。

### O2 · BEM 名与 components 草案差异

- components 草案：`payment-method-card__incentive`（Payment 假卡模板族）。
- 施工：结账主路径本就不是 `payment-methods.phtml` 卡，而是 Checkout `weline-checkout__*` —— **贴真壳，符合「禁脱离主题」**。
- data 钩子 `data-payment-incentive` / `data-checkout-payment-incentive-row` 与冻结一致。

---

## 与前端施工交叉核对

| 前端声称 | 本席核对 |
|----------|----------|
| SSR 徽章消费 `incentive_display` | ✅ HtmlRenderer L340–354 |
| 摘要分列行 | ✅ index.phtml L225–228 |
| 切换即时 renderTotals + reconcile | ✅ L3679–3710 · L3080–3114 传 `payment_method` |
| 契约测覆盖 data-row / incentive class | ✅ `CheckoutHtmlRendererTest` 源扫描断言存在 |

---

## gate

| 门禁 | 状态 |
|------|------|
| `ui_prototype_gate_before_test` · 原型审查 | **pass** → 允许测试席执行真 Browser（仍须 UI 席过签） |
| 本席否决权 | **不行使** |

---

## result

`closed` · **verdict=pass** · `notify_pm: true`

**@项目经理：本席已交付/上报，请检查并更新 SESSION。** 禁改 SESSION（本席未动）。请确认 UI 席 `acceptance-ui` 一并 pass 后再唤醒测试真 Browser。
