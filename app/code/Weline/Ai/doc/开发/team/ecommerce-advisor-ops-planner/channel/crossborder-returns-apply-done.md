# channel — 跨境退换 / 批发口径施工完成纪要

日期：2026-09-23  
角色：施工席（可改码）  
权威 brief：`crossborder-returns-wholesale-ops-brief.md`  
`client_session_id`：`694e987c-4222-4a8a-9448-a1ef03441455`

## result

**closed**

## paths_changed（要点）

| 模块 | 路径 / 动作 |
|------|-------------|
| Shipping | `view/templates/frontend/guide/returns.phtml`：1.1–1.4 重写为「发货后原则上不退」；新增批发段；不予退换/运费 4.x/尺码软表述对齐 |
| Shipping | `app/design/Weline/hanfu/Weline_Shipping/templates/frontend/guide/returns.phtml`：与模块同步 |
| Theme | `layouts/policy/refund.phtml`：质量/物流责任为主；删软无理由主推 |
| Theme | `layouts/policy/default.phtml` 5.2/5.4：去掉「法定无理由」冲突主推句 |
| Theme | `widgets/content/trust-badges/default.phtml`：money-back →「质量问题可退换」 |
| B2B | `faq/b2b-wholesale.phtml`：显式「正常范围内损耗不支持退货」 |
| Faq | `FaqSeedCopyCatalog` returns + hub；`faq-crossborder-returns-wholesale.v1.php` + remediate（returns + hub_0 × 默认站 locale） |
| I18n | `dict-fill-crossborder-returns-wholesale.v1.php` + upsert（31 词 × 39 locale = 1209 行）；`publishLocale` 抽检 en/ru/de/fr/es/ar |
| CSV | Shipping / Theme / B2B 中英 CSV 新源串；`i18n:collect` Shipping/B2B/Theme |
| Tests | `GuideEnglishTranslationTest`、`HanfuStorefrontLocaleContractTest` 对齐新串 |
| Versions | Shipping 2.9.28 / Theme 2.2.603 / Faq 1.0.21 / B2B 2.6.89 / I18n 1.0.86 + 开发日志 |

## dictionaries / FAQ 写入摘要

- 默认站 locale：40（含 zh_Hans_CN）；词典包跳过中文源 locale，写入 39 × 31 keys。
- FAQ `faq_key=returns`（retail template）+ `hub_0`（site）全 locale 已 apply；抽检 zh/en/de/ar/hi `matches_pack=true`。
- 禁止：未写回「七日无理由」店内福利；未启动 Ollama。

## curl 抽检（本机 `https://p05113ef3.test.weline.com:9555`，nocache）

| URL | 结果 |
|-----|------|
| `/zh_Hans_CN/guide/returns` | HIT 发货后原则上 / 质量 / 批发 / 损耗；无「七日无理由」店内承诺 |
| `/guide/returns` | HIT After dispatch / quality / Wholesale / We do not offer 7-day… |
| `/zh_Hans_CN/faq` | HIT 发货后原则上 / 不提供七日 |
| `/ru_RU/guide/returns` | HIT После отправки / качеств…；无七日主推 |
| `/ru_RU/faq` | HIT После отправки / качеств… |
| `/zh_Hans_CN/faq/b2b-wholesale` | HIT 正常范围内损耗 / 批发退换 |

## 契约

- `GuideEnglishTranslationTest` return eligibility：OK

— 施工席 · 2026-09-23
