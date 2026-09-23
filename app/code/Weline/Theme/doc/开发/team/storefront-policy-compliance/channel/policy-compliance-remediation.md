# channel/policy-compliance-remediation

## msg-001 · from:电商顾问 · kind=escalate · 2026-09-22

@项目经理：请立刻组队解决

**supported_countries（结账可达）**：AT,AU,BE,BR,CA,CH,CN,DE,DK,ES,FI,FR,GB,HK,IE,IT,JP,MX,NL,NO,PL,PT,SE,SG,US  
来源：Shipping defaultCoverage ∩ PayPal∪Stripe capability；默认站语种约 40（辅助）。

**verdict=fail** · stance=异议

| # | severity | 国家 | 主题 | 摘要 | 来源 |
|---|----------|------|------|------|------|
| F1 | P1 | CN+EU | 退换披露 | FAQ「7天退换」「快递1–5天」与退款/配送政策及 EU 14 日撤回权冲突 | Your Europe 14-day withdrawal |
| F2 | P0 | CN | 隐私存储 | 「仅境内存储」未如实覆盖 PayPal/Stripe 等境外处理 | PIPL 跨境告知要点 |
| F3 | P1 | EU | Cookie | 政策仅浏览器管理，缺非必要 Cookie 先同意表述 | ePrivacy/EDPB |
| F4 | P1 | ALL | 营销 | 顶栏「全球配送，售后无忧」绝对宣称 | 广告/不实宣传风险 |
| F5 | P2 | ALL | 退款1.2 | 模板与 CSV 两套 1.2 文案不一致 | 仓内 |
| F6 | P2 | — | 市场覆盖 | default-markets ≫ 支付可达（本波仅记，不改承运库） | 本机解析 |

**suggested_seats**：主题、前端（i18n）、安全（隐私措辞复核可选）  
**options**：A 先改店面可见文案/政策页对齐；B 同期做 Cookie 同意门禁（更大）；C 停工等法务专审。  
**recommendation**：A 本波立刻做；B 另立项；C 否。

## msg-002 · from:项目经理 · kind=stance · 2026-09-22

同意采纳 A。同回合拉主题改：顶栏、FAQ CSV、隐私 4.2、退款 1.2 统一、Cookie 政策加同意承诺句。F6 本波 skip（记入会议，不改承运）。Cookie 门禁实现另开。

## msg-003 · from:项目经理 · kind=依赖唤醒 · 2026-09-22

@主题：请立刻按 msg-001/msg-002 施工。agent=[主题](42aef9ef-a55d-46f7-9c53-9d1d9fd4c043)。完成后 channel 回报 paths_changed + result=closed。

## msg-004 · from:主题 · kind=closed · 2026-09-22

role=主题 · result=closed · 轨=施工

**paths_changed**
- `app/code/Weline/Theme/view/theme/frontend/widgets/header/full-header/default.phtml`（顶栏：国际配送，售后有保障）
- `app/code/Weline/Theme/view/theme/frontend/layouts/policy/privacy.phtml`（4.2 境内为主 + 境外支付/物流告知）
- `app/code/Weline/Theme/view/theme/frontend/layouts/policy/refund.phtml`（1.2 对齐 CSV 完整句）
- `app/code/Weline/Theme/view/theme/frontend/layouts/policy/cookie.phtml`（非必要 Cookie 先同意）
- `app/code/Weline/Theme/i18n/zh_Hans_CN.csv`
- `app/code/Weline/Theme/i18n/en_US.csv`
- `app/code/Weline/Theme/test/Unit/HeaderNavNoGiftLinksContractTest.php`（契约源串同步）

**done**
- F4 顶栏去掉「无忧」绝对宣称
- F1 FAQ 发货/退换 CSV 双列对齐配送与退换政策（旧键 col2 映射新文案）
- F2 隐私 4.2 覆盖 PayPal/Stripe 等境外处理，未编造主体地址
- F5 退款 1.2 模板与 CSV 完整句统一
- F3 Cookie 管理节增加非必要 Cookie 同意后再启用
- `php bin/w i18n:collect Weline_Theme` exit 0（WLS 清缓存告警可忽略）

**skip**：承运库 / Cookie 门禁 JS（按 msg-002）

## msg-005 · from:项目经理 · kind=汇审closed · 2026-09-22

抽检 PASS：顶栏源串、隐私 4.2 新键、退款 1.2、Cookie 同意句、FAQ col2 对齐；`HeaderNavNoGiftLinksContractTest` 5/5 OK。

**本波 closed（F1–F5）**。F6 市场覆盖裁剪 + Cookie 同意门禁实现 → backlog，另立项。

## msg-006 · from:项目经理 · kind=i18n · 2026-09-22

用户追问翻译覆盖：整改时仅 zh/en CSV。已补默认站 39 非中英语种系统词典（8 源串×39=`dict-fill-policy-compliance-remediation` + publishLocale）。FAQ 实体表「签收后 7 日内」另案。

## msg-007 · from:项目经理 · kind=依赖唤醒 · 2026-09-22

用户确认 FAQ 必须改 + 默认站全语种。已拉施工席改 `returns`/`hub_0` 种子与库行。[FAQ施工](8763e0a2-90bb-4723-9cce-ac50a84cbee8)

## msg-008 · from:项目经理 · kind=汇审closed · 2026-09-22

FAQ 合规+全语种收口：returns/hub_0 各 40 locale（update10+insert30）；UT 5/5；旧「签收后 7 日 / 1–3 发货」库内已清。交付 `/faq`。

## msg-009 · from:项目经理 · kind=bugfix · 2026-09-22

用户：`/ru_RU/faq` 整页英文。根因 Hub 只读 Catalog→en 回退。已改 `FaqHubContent` DB 优先（1.0.18）。并行 [Hub全语种](c30e0939-db5f-45c9-9b11-febc548d6573) 补 hub_0..7×40。

## msg-010 · from:项目经理 · kind=closed · 2026-09-22

Hub×40 落库 + FPC 清后抽检：`/ru_RU/faq` 八条问答为俄语（非 How soon / In-stock）。主题卡片 WidgetI18n 英混另案。

## msg-011 · from:项目经理 · kind=closed · 2026-09-22

提示词根因已修（[提示词优化](f5747749-6b3d-494a-9c5e-6b83435ded34)）：禁「其它语种默认不做」；改可见文案须默认站全语种；顾问合规面含 FAQ Hub 且 suggested_seats 须含翻译工程师。契约测通过。

## msg-012 · from:项目经理 · kind=依赖唤醒 · 2026-09-22

全站再扫遗漏：`/guide/returns` 1.1 仍绝对 7 日；trust-badges「无忧退款/30天无理由」。已拉 [合规翻译](55d39fd7-c825-4641-897c-776ea90e535e) 先合规后默认站全语种。

## msg-013 · from:项目经理 · kind=closed · 2026-09-22

[合规翻译](55d39fd7-c825-4641-897c-776ea90e535e) closed：returns 1.1 + trust-badges 合规；词典 8×39 publish OK；`/guide/returns` 与 `/ru_RU/guide/returns` 无 within 7 days 绝对宣称。
