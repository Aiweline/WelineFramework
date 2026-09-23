# 翻译复审 · policy-copy-tone-soften（i18n-dict）

- 席位：Team:翻译工程师:（alias i18n）
- plan_id：`i18n-dict`
- 波次：construction · 巡检施工 + 复审
- 日期：2026-09-23
- verdict：**pass**（本波已软源串词典闭环；非中英抽检 pass）

---

## 范围

- Theme 政策页 `layouts/policy/*` + `layouts/terms/default.phtml` 本波软化源串（hero/intro/TOC/关键软句）
- 模块 CSV：**仅** `Weline_Theme/i18n/zh_Hans_CN.csv` + `en_US.csv`
- 默认站其它 locale → 系统词典 upsert + `publishLocale`（禁止非中英模块 CSV；未启动 Ollama）

---

## 默认站 language_codes

API：`Weline\Websites\Model\WebsiteLanguage::getWebsiteLanguageCodes(Website::ID_DEFAULT)`（website_id=0）

**count=40**（含基线 zh_Hans_CN + en_US）：

`en_US, ar_SA, bn_BD, es_ES, fr_FR, hi_IN, id_ID, pt_BR, ur_PK, zh_Hans_CN, bg_BG, ca_ES, cs_CZ, da_DK, de_DE, el_GR, en_GB, es_MX, et_EE, fi_FI, fr_CA, ga_IE, hr_HR, hu_HU, is_IS, it_IT, lt_LT, lv_LV, mt_MT, nb_NO, nl_NL, pl_PL, pt_PT, ro_RO, ru_RU, sk_SK, sl_SI, sv_SE, tr_TR, uk_UA`

---

## 中英 CSV

| 检查 | 结果 |
|------|------|
| policy/* + terms/default 源串圈定 | 345 unique labels；en 缺行=0；en 中文占位=0 |
| 软 hero/intro（14）+ hub/软导语 | dirty-load 后均有真实英文第二列 |
| 模板源串仍为简中 | 未把源串改成英文 |
| 模块 `i18n/` 非中英 CSV | 无（仅 zh_Hans_CN + en_US） |

`php bin/w i18n:collect Weline_Theme` → **成功**（2026-09-23；含缓存清理）

---

## 系统词典（其它已选 locale）

| 项 | 值 |
|----|----|
| pack | `app/code/Weline/I18n/scripts/data/dict-fill-policy-copy-tone-soften.v1.php` |
| script | `app/code/Weline/I18n/scripts/remediate-dict-fill-policy-copy-tone-soften.php --apply` |
| words | 81（软 hero/intro/hub/关键导语 + 软 TOC） |
| writes | 3159（81 × 39 non-zh locales） |
| missing | 0 |
| publishLocale | **39/39 ok** |

主要语种（ar/de/es/fr/hi/id/it/nl/pl/pt/ru/tr/uk/ur/bn 等）为真实目标语；部分低流量 EU locale 对长句回落英文（与前波 dict-fill 惯例一致），抽检以 ru_RU 为准。

---

## 抽检（≥1 非中英）

locale=`ru_RU`（词典行，禁 CJK 占位）：

| 源串摘要 | ru_RU 预览 |
|----------|------------|
| 欢迎逛店、下单… | «Добро пожаловать: смотрите витрину и оформляйте заказы…» |
| 我们从中国发往海外… | «Мы отправляем из Китая за рубеж…» |
| 必要 Cookie… | «Необходимые Cookie поддерживают вход, корзину и оформление…» |
| 我们会认真保护您的个人信息… | «Мы бережно защищаем ваши персональные данные…» |

`de_DE` 同批抽检无 CJK。**pass**

related_web_urls（相对 path，供顾问/Browser）：

- `/policy/privacy`
- `/policy/term-condition`
- `/policy/refund`
- `/policy/cookies`
- `/policy/shipping`
- `/policy/disclaimer`
- `/policy/accessibility`

---

## 缺口 / escalate

- 无阻断本席闭环项。
- terms/default 本波已软（theme-terms-soft closed）；与 term-condition 共享软导语键，已进词典。

---

## 检查清单

- [x] 解析默认站 language_codes
- [x] 源串简中；en 真实英文
- [x] 仅中英模块 CSV；其它 locale 系统词典
- [x] collect Weline_Theme
- [x] upsert + publishLocale
- [x] 抽检 ≥1 非中英（ru_RU）
- [x] 未代跑商品翻译优化；未启 Ollama

**result=pass / closed**
