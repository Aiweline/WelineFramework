# channel — WO-HP-P1-04 done（locale 整页语言一致）

日期：2026-09-22  
工单：`WO-HP-P1-04`  
席位：`Team:翻译工程师:`（协同 Theme / 商品名展示策略）  
状态：`closed`  
`notify_pm`: true

## 默认 locale 意图（brief 未到位时开工口径）

- `homepage-wave2-ops-brief.md` 本席开工时不存在。
- 按 `homepage-continuous-review.md` P1-4 + PM 排期：**按当前请求 locale 整页一致**（默认站 `en_US` → 全英；`/zh_Hans_CN/` → 全中）。

## 改动摘要

1. **Promo 英文图注串语**  
   - 根因：`background_image_file_html` 内嵌 `file-image` 的英文 `<figcaption>Free Shipping</figcaption>`，中文站与中文 promo 文案并排可见。  
   - 修：`promo-banner`（Theme + hanfu design）渲染前剥离 figcaption，并 CSS `display:none` 兜底。

2. **WidgetI18n 回落语种**  
   - 无 path / RequestContext 时改为 `State::resolveWebsiteDefaultLanguage()`，禁止硬编码 `zh_Hans_CN`（默认站为 `en_US` 时避免部件标题回落中文）。

3. **布局种子禁中英并写**  
   - `DefaultLayoutSeeder` 货架/推荐标题改为简中源串（如 `本季精选`）；英文靠模块 CSV。  
   - Theme `i18n/zh_Hans_CN.csv` + `en_US.csv` 补：`为日常与仪式感而作` / `本季精选` / `同风格推荐` / `为你推荐` / `继续探索` / `搭配成套` / `人气商品`。

4. **商品名展示**  
   - 抽检确认 `StorefrontProductWidgetCatalog::mapOffer` 使用的 offer `name` 已随 locale 投影（EN 英文名 / ZH 中文名）；本席未改 P2 专属面。

5. **契约**  
   - `PromoBannerFigcaptionLocaleContractTest`  
   - `WidgetI18nWebsiteDefaultLocaleFallbackContractTest`  
   - `ThemeHanfuHomepageDefaultsContractTest::testLayoutSeederUsesChineseSourceCommerceCopy`  
   - PHPUnit：上述相关 **11 tests OK**。

6. **版本**  
   - `Weline_Theme` `2.2.555` → `2.2.556`；开发日志已记。

## 验收证据

- 默认：[https://p05113ef3.test.weline.com:9555/](https://p05113ef3.test.weline.com:9555/)（`data-local=en_US`）  
  - Deals / Bestsellers / Reviews / Looks / Promo 均为英文；商品名为英文。  
- 切换：[https://p05113ef3.test.weline.com:9555/zh_Hans_CN/](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/)  
  - 对应区块为中文；商品名为中文；**无** `Free Shipping` 图注残留。  
- nocache：`Cache-Control: no-cache` curl 抽检；Cursor Browser MCP 本机会话不稳定（navigate 失败），已用 `open_resource` 打开验收面 + HTTP 抽检闭环。

## related_web_urls

- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/zh_Hans_CN/

## 备注

- `php bin/w i18n:collect Weline_Theme` 本机与并行任务争用时出现长时间挂起；模块 CSV 已手写落盘，`TranslationResolver` 直接读模块 CSV。建议 PM/运维空窗再跑一次 collect 刷新 phrase 广播。  
- 未抢 P2-05/06/07/08 专属面（信任条位置、Newsletter、迷你车进度、Hero CTA 结构）。
