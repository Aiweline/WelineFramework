# 翻译 · review — hanfu-theme-editor-optimize · UC-P1-04

日期：2026-09-23  
席位：`Team:翻译工程师:`  
verdict： **pass**（模块中英 + 默认站非中英语种词典；店面 HTTP 本回合不可用，以 language pack 抽检）

## 范围

Hero CTA 可见串（`浏览精选` / `按场景选` / 预置 `即刻寻衣`），对齐 ops-brief「单主 CTA + 次文字链」可能改串。

## locales_resolved

`w_weline_websites_website_language` website_id=0 → 40 codes：

`ar_SA,bg_BG,bn_BD,ca_ES,cs_CZ,da_DK,de_DE,el_GR,en_GB,en_US,es_ES,es_MX,et_EE,fi_FI,fr_CA,fr_FR,ga_IE,hi_IN,hr_HR,hu_HU,id_ID,is_IS,it_IT,lt_LT,lv_LV,mt_MT,nb_NO,nl_NL,pl_PL,pt_BR,pt_PT,ro_RO,ru_RU,sk_SK,sl_SI,sv_SE,tr_TR,uk_UA,ur_PK,zh_Hans_CN`

## 落盘

| 载体 | 结果 |
|------|------|
| Theme `zh_Hans_CN.csv` + `en_US.csv` | 三词齐全；en 非中文占位 |
| LocaleDictionary upsert | 3×39 = 117 writes |
| `publishLocale` | 39 非中英语种 OK |
| `php bin/w i18n:collect Weline_Theme` | **success**（缓存广播 control port 拒连，收集完成） |

未写非中英模块 CSV。禁 Ollama。未改布局/CSS。

## spot_check

- `generated/language/ru_RU.php`：三词均为俄语真实译文（PASS）
- `generated/language/de_DE.php` / `fr_FR.php`：同上（PASS）
- 店面 `/ru_RU/` HTTP：本回合 400/502（WLS 不可用）— 记 follow-up，不挡本席词典闭环

## 与部件衔接

design 层次 CTA 已 `slide-text-link`；源串仍 `浏览精选`/`按场景选`。`即刻寻衣` 预置待部件若切主 CTA 消费。
