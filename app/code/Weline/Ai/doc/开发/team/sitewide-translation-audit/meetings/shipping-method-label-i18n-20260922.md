# 配送方式名称 i18n（结账成功页 locale leak）— 2026-09-22

席位：Team:翻译工程师:（i18n）  
`client_session_id`：`shipping-method-i18n-20260922`  
现象：英文店面结账成功/订单确认「Order items」下 `Shipping Method: 美洲`（标签已英译，航线名仍中文）。

## 范围

`DefaultShippingLaneSeedService` 种子中文 name（11）：

国内标快、港澳台、亚太、美洲、欧洲、大洋洲、拉美、中东非洲、其他可达市场、国内重货、国际重货

## CSV（仅 zh_Hans_CN + en_US）

| 模块 | 结果 |
|------|------|
| `Weline_Shipping` | 11 源串中英已齐（例：`美洲`→`Americas`，`国内重货`→`Domestic heavy freight`） |
| `Weline_Checkout` | 原缺 `国内重货`/`国际重货`；已补中英行 |

## 默认站 locale（website_id=0，40）

`ar_SA bg_BG bn_BD ca_ES cs_CZ da_DK de_DE el_GR en_GB en_US es_ES es_MX et_EE fi_FI fr_CA fr_FR ga_IE hi_IN hr_HR hu_HU id_ID is_IS it_IT lt_LT lv_LV mt_MT nb_NO nl_NL pl_PL pt_BR pt_PT ro_RO ru_RU sk_SK sl_SI sv_SE tr_TR uk_UA ur_PK zh_Hans_CN`

## 词典

- 包：`app/code/Weline/I18n/scripts/data/dict-fill-shipping-lane-names.v1.php`
- 脚本：`remediate-dict-fill-shipping-lane-names.php`
- dry-run：`words=11 writes=429 missing=0 locales=39`
- apply upsert：`upsert_writes=429`（非中英全 locale）
- `publishLocale`：非中英 37 locale（含 `ru_RU`/`de_DE`）全部 ok；另补 `en_US`/`en_GB` ok（`summary ok=35 fail=0` + 先发 2 + 英系 2）

## 抽检

| locale | 源串 | 译文 | 证据 |
|--------|------|------|------|
| `ru_RU` | 美洲 | Америка | `w_i18n_locale_dictionary` + `generated/language/ru_RU.php` |
| `de_DE` | 美洲 | Amerika | 词典 + published |
| `ar_SA` | 美洲 | الأمريكتان | published |
| `en_US` | 美洲 | Americas | 模块 CSV + 词典 |

非中英抽检：译文非中文原样。

## 展示层

父会话负责模板/`CheckoutHtmlRenderer` 对 `service_name` 套 `__()`；本席保证中英 CSV + 默认站词典键齐全。未套 `__()` 时运行时仍会露中文源串。

## collect

- `Weline_Checkout`：`localization collection successful`（缓存清理 warning：`Not all WLS Workers completed cache clearing`，收集本身完成）
- `Weline_Shipping`：曾遇 `I18N_DICTIONARY_COLLECT_BUSY`（他席 `Weline_Framework` collect）；锁释放后 `localization collection successful`（同缓存清理 warning）

未启动 Ollama；未写非中英模块 CSV；未 SSH 生产。
