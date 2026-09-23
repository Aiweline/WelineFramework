# 翻译 · review（全站漏译巡检）

## 2026-09-23 — 邮件模板 / 壳品牌多语（subscribe_gift）

- seat: Team:翻译工程师:（本回合工程联动 Smtp/Websites/Marketing）
- verdict: **pass**（发件记录 `log_id=26` embed 预览：en_US 页头简介 / Welcome gift / Need help? 均为英文，可见区无中文正文）
- 根因：① Website LocalDescription 缺非中文简介；② 壳可视化 footer override 中文盖掉 `<lang>`；③ Marketing 挽回信残留「优惠码：」
- 落盘：Website Local 39 语 + 品牌/壳短语 LocaleDictionary；Smtp/Websites 中英 CSV 补简介与店名；Marketing 模板 syncAll
- collect：`php bin/w i18n:collect Weline_Smtp` success；Websites 当时 BUSY（pid 并行），品牌 CSV 已写入待后续 collect 消化
- 抽检：`en_US` Browser 预览 + `es_ES`/`fr_FR`/`de_DE`/`ar_SA` 壳 footer 无中文源串

---

## 2026-09-22 — 全站漏译巡检

- seat: Team:翻译工程师:
- wave: translation-engineer-charter / 全站看一下反应.翻译
- date: 2026-09-22
- verdict: **pass**（模块中英 CSV 缺口清零；其它默认站语种词典填补为待办）

## locales_resolved（本机，未 SSH）

`WebsiteLanguage::getWebsiteLanguageCodes(Website::ID_DEFAULT)` → 39 codes，默认优先 `en_US`：

`en_US, ar_SA, bn_BD, es_ES, fr_FR, hi_IN, id_ID, pt_BR, ur_PK, zh_Hans_CN, bg_BG, ca_ES, cs_CZ, da_DK, de_DE, el_GR, en_GB, es_MX, et_EE, fi_FI, fr_CA, ga_IE, hr_HR, hu_HU, is_IS, it_IT, lt_LT, lv_LV, mt_MT, nb_NO, nl_NL, pl_PL, pt_PT, ro_RO, ru_RU, sk_SK, sl_SI, sv_SE, tr_TR, uk_UA`

基线模块 CSV 仅维护：`zh_Hans_CN` + `en_US`。

## collect_status

| 轮次 | 命令 | 结果 |
|------|------|------|
| 改前（父会话） | `php bin/w i18n:collect` → `/tmp/i18n-collect-sitewide.log` | success：`Language packs collected successfully!` + cache cleared |
| 改后 | 全仓/分模块 `i18n:collect`（本席与并行席同时有 Theme/Admin 等 collect；日志交替） | 至少一轮全仓 success；余量模块 `Weline_HelpPay Weline_Seo Weline_Deploy` 另收 |
| 说明 | 禁止用 `cache:clear` 代替 collect | 遵守 |

## 巡检与译修

1. 扫描全仓 `app/code/**/i18n/en_US.csv`：空译 / 第二列=中文 source / 第二列仍含大量汉字。
2. 初扫约 **6776** 行缺口、**79** 模块（几乎全为 `same_as_zh`）。
3. 高曝光优先人工写英：Admin(4) / Acl(6) / Backend(72) / Cart(95) / Checkout(159) / Customer(137) / Framework(131) / I18n(214)。
4. 其余大量缺口：从本机 `LocaleDictionary`（`en_US`）回填有效英文约 **5908** 行，覆盖 Product/Theme/B2B/Payment/… 等；再人工收尾余量（含 Deploy 乱码键、HelpPay、Seo 等）。
5. 终扫：`en_US` 上述三类缺口 **0**（含 zh 身份列补齐确认）。

### modules_fixed（约）

| 范围 | 约行数 | 方式 |
|------|--------|------|
| Weline_Admin / Acl / Backend / Cart / Checkout / Customer / Framework / I18n | ~818 | 本席直接英译写 CSV |
| Weline_Theme / Product / Payment / Order / Shipping / Visitor / Meta / …（约 60 模块） | ~5900 | 系统词典 en_US 回填 + 人工尾差 |
| Weline_HelpPay / Seo / Deploy（尾差） | ~4 | 人工 |
| **合计** | **~6700+** | 仅 `zh_Hans_CN.csv` + `en_US.csv`；未写非中英模块 CSV |

未代跑商品 content_ops「翻译优化」。未把模板源串改成英文。

## remaining_gaps

### 模块 CSV（en_US）

- 扫描口径下：**0**（空/同中文 source/仍中文第二列）。
- 备注：`Weline_Deploy` 存在一条引号膨胀的异常 source 键（Webhook `ok:true` 说明文案被 CSV 转义污染）；已填英译，建议归属席清理源串后重 collect。并行席多次 `i18n:collect` 可能短暂回写出占位，终扫已再清零。

### 系统词典多语（用户提了「翻译」）

默认站另有 **37** 个非中英语种需进 **LocaleDictionary** + `publishLocale`，**禁止**写 `ja_JP.csv` 等模块 CSV。

本回合未批量导入其它语种（禁 Ollama；体量=缺口×语种过大）。

**命令指针（后续席/PM 排期）：**

- `php bin/w ai:import-csv`（词典导入；跳过 CJK 占位）
- 既有 remediate 样板：`app/code/Weline/I18n/scripts/remediate-dict-fill-*.php` + `scripts/data/dict-fill-*.v1.php`（upsert + `publishLocale`）
- 远程协助：`I18n` RemoteTranslation ingest → LocaleDictionary + publishLocale（见 `app/code/Weline/I18n/doc/rest-api-remote-translation.md`）
- 规范：`app/code/Weline/I18n/doc/模块翻译CSV规范.md`「默认网站全语种」

### 店面仍可能露中文（非本席 CSV 漏译口径）

- 语言切换器显示名（「中文」「简体中文」「日本語」等）——语种自名，可保留。
- 品牌「长安汉服」——商标/品牌例外。
- 商品/活动标题等 **content_ops 字段**（非模块 CSV）——不归本席。
- 部件配置若把中文 title **固化进已发布配置且未走 `__()`/WidgetI18n`**，需 Theme/部件席配合（CSV 已有 `今日特价`→`Today's Deals`）。

## spot_check

Host：`https://p05113ef3.test.weline.com:9555`（本机；默认站 default=`en_US`，`/en_US/*` 常 301→无前缀路径）。

| URL | 证据 |
|-----|------|
| `/en_US/customer/account/login` → `/customer/account/login` | title `Log in`；可见 Sign in / Email / Password |
| `/en_US/cart` → `/cart` | title 含 `Cart`；可见 Empty / Checkout |
| `/`（默认 en） | 顶栏英文 chrome（Login/Cart/Account/Checkout/Subscribe）；CJK 主要为语言名+品牌+个别营销标题 |

CSV 抽检：`菜单`→Menu；`确认并付款`→Confirm and pay；`今日特价`→Today's Deals；`账户中心`→Account center。

## review_path

`app/code/Weline/Ai/doc/开发/team/translation-engineer-charter/meetings/翻译-review.md`

## related_web_urls

- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/customer/account/login
- https://p05113ef3.test.weline.com:9555/cart

## result

`closed`（中英模块 CSV 全站缺口闭环）+ **notify_pm: true**（其它语种词典待排期）。

@项目经理：本席已交付中英 CSV 全站漏译清零与 collect/抽检证据；默认站非中英语种系统词典填补请检查并更新 SESSION / 排期。
