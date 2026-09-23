# 翻译-align · payment-method-incentive-discount

- date: 2026-09-22
- seat: Team:翻译工程师:
- thread: align-freeze
- kind: stance（对齐冻结；**本席不写生产码**）
- verdict: **同意冻结**（在下列 i18n 契约写入 contracts 的前提下）

## 1. 表态摘要

结账列表「减 X」、摘要「支付方式优惠」等**用户可见串**须进入本 feature 的 i18n 闭环：

| 落盘面 | 规则 |
|--------|------|
| 模块 CSV | **仅** `zh_Hans_CN.csv` + `en_US.csv`（禁止新建/写回 `ja_JP`/`ru_RU` 等模块 CSV） |
| 其它默认站已选 locale | **系统词典**（`LocaleDictionary` / collect 后词典 upsert + `publishLocale`），禁止 invent 非中英模块 CSV |
| 源串 | 模板/JS/PHP 用户可见源串默认**简体中文**；`en_US` 第二列须真实英文，不得留中文 source |
| 施工后硬步骤 | `php bin/w i18n:collect`（至少 `Weline_Payment`，触及多模块则全仓）→ 抽检 `en_US` + **≥1 非中英** locale |

**禁止误读**：顾问约束「禁止只改中英 CSV」= **禁止只做中英就收口**；其它语种仍须进词典。**不等于**允许模块仓库写非中英 CSV。

## 2. 可见串范围（首期须登记 contracts）

施工席落地文案时须可 `__()` / `<lang>` / `@lang` 收集；本席对齐冻结钉死最小集合（金额数字可插值，文案键/源串须稳定）：

| 面 | 示例源串意图（简中） | 归属倾向 |
|----|----------------------|----------|
| 支付方式列表 | 「减 ¥{X}」「减 {X}%（约 ¥{Y}）」「可选减」 | `Weline_Payment`（或结账壳若已有支付列表模板归属，以扩展点/前端席最终归属为准，但须进中英 CSV） |
| 订单摘要行 | 「支付方式优惠」（与「优惠券」分列） | 同上；禁止与券行合并成模糊「优惠」单一可见标签若产品要求分列 |
| 不可用/未配 | 无激励时不得展示可点击误导「可减」；若有空态/禁用说明亦须可译 | 同上 |
| 后台配置（若 MVP 含） | 激励开关/固定额/百分比/有效期等表单 label、toast | `Weline_Payment` 后台 |
| FAQ/政策触达（若本波触及） | 须同波拉本席；FAQ 实体多语走归属表，非模块 CSV | 顾问条件 5；本波默认 **不主动扩 FAQ**，触及再 escalate |

金额格式：币种符号/小数位跟站内 Money/locale 展示；**禁止**英文模板硬编码「Save $X」当唯一源串。

话术红线（对齐顾问）：禁止「最高减」等不可兑现文案；展示「减 X」= 可兑现扣减额。

## 3. 施工后验收清单（翻译席 / 施工席自带本席技能齐做）

对齐冻结通过后，**施工波**（非本会）必须：

1. 新可见串源串 = 简中；写入归属模块 `i18n/zh_Hans_CN.csv` + `i18n/en_US.csv`。
2. `php bin/w i18n:collect`（禁止只改 CSV 不 collect；禁止用 `cache:clear` 代替）。
3. 解析默认站 `WebsiteLanguage::getWebsiteLanguageCodes(Website::ID_DEFAULT)`：非中英语种进系统词典（禁止只补 en）。
4. 抽检：`en_US` 结账列表/摘要不再露中文正文；≥1 非中英 locale（如 `ru_RU`/`de_DE`）店面同路径不露中文 source、不错误回落英文 catalog。
5. 证据落盘：`meetings/翻译-review.md`（CSV 关键行 + collect 回执 + 抽检 locale）。

本席对齐冻结会 **不写生产码、不跑 collect**；仅冻结契约。

## 4. 对 contracts / deps 的冻结请求

请架构师/测试写入 contracts（或 surfaces i18n 节）：

- `i18n.module_csv = zh_Hans_CN + en_US only`
- `i18n.other_locales = system_dictionary`
- `i18n.post_impl = collect + spotcheck(en_US + ≥1 non-zh-en)`
- 可见串清单引用本节 §2；字段名（payload）与展示文案解耦——payload 键可英文技术名，**UI 标签必须可译简中源串**

若 contracts 把「仅中英」写成「其它语种默认不做」→ 本席改 **否决**。

## 5. open_questions（翻译侧）

无阻塞翻译冻结的开放点。部分退/PayPal 映射/Ledger/payload 命名属技术项；只要新增用户可见标签仍走 §2–§3。

## 6. notify_pm

- result: **delivered**
- stance: **同意**（条件：contracts 写入上列 i18n 契约，且不偷改「全语种=CSV+词典」语义）
- notify_pm: **true**
- @项目经理：本席已交付/上报，请检查并更新 SESSION

paths_changed:

- `meetings/翻译-align.md`（本文件）
- `channel/align-freeze.md`（msg stance）
