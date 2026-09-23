# channel — 退换政策语气柔化（实质不变）

日期：2026-09-23  
角色：施工席（可改码）  
`client_session_id`：`694e987c-4222-4a8a-9448-a1ef03441455`

## result

**closed**

## 政策实质（不变）

- 发货后个人原因退换通常难以安排
- 质量 / 错发 / 运输损坏可退、会认真处理
- 批发正常范围内损耗通常不作为退货理由
- 不做七日/十四日无理由店内福利（改为「与部分平台无理由不同，售后重心是质量与发货准确性」弱表述）
- 法定例外仅强制范围内弱披露

## paths_changed（要点）

| 模块 | 路径 / 动作 |
|------|-------------|
| Shipping | `guide/returns.phtml`（模块 + hanfu design）语气柔化 |
| Theme | `refund.phtml`、`policy/default`、trust-badges money-back 描述 |
| B2B | `faq/b2b-wholesale` 损耗说明 |
| Faq | `FaqSeedCopyCatalog` + `faq-soft-tone-returns.v1` remediate |
| I18n | `dict-fill-soft-tone-returns.v1` + remediate + publishLocale |
| CSV | Shipping / Theme / B2B 中英新源串；`i18n:collect` |
| Tests | `GuideEnglishTranslationTest` 对齐新 1.1 |
| Versions | Shipping 2.9.29 / Theme 2.2.605 / Faq 1.0.22 / B2B 2.6.90 / I18n 1.0.87 |

## curl 抽检（本机 `https://p05113ef3.test.weline.com:9555`，nocache）

| URL | 结果 |
|-----|------|
| `/zh_Hans_CN/guide/returns` | HIT 跨境售后说明 / 通常难以安排 / 质量与发货准确性 / 通常不作为退货理由；无「原则上不支持」「本店不提供七日」「买了不喜欢」硬句 |
| `/guide/returns`（默认英） | HIT Cross-border after-sales / usually difficult / Unlike no-reason…；无 We do not offer 7-day |
| `/en_US/guide/returns`（-L） | 同上 soft EN |
| `/ru_RU/guide/returns` | HIT После отправки / обычно сложно |
| `/zh_Hans_CN/faq` | HIT 通常难以安排 / 质量与发货准确性 |
| `/en_US/faq`（-L） | HIT usually difficult / quality issues / Unlike no-reason |
| `/ru_RU/faq` | HIT обычно сложно / качество |
| `/zh_Hans_CN/faq/b2b-wholesale` | HIT 通常不作为退货理由 |

## 契约

- `GuideEnglishTranslationTest`：6/6 OK
- dict-fill soft-tone：22 words × 39 locales = 858 writes；publishLocale 39 OK
- FAQ remediates returns + hub_0：matches_pack 抽检 zh/en/de/ar/hi = true

— 施工席 · 2026-09-23
