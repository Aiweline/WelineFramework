# channel — WO-HP-P2-08 主题席回执

日期：2026-09-22  
席位：`Team:主题开发工程师:`（协同前端意图落地；一席施工）  
工单：`WO-HP-P2-08`（Hero 1 主 CTA + 1 次 CTA）  
`work_mode`: **`default_theme`**（席规范；用户口令 `theme_runtime` 对齐为默认 Theme 前台部件/layout + i18n；汉服 design 层 Hero 同步防漂移）  
顾问 brief：`homepage-wave2-ops-brief.md`（P2-08 拍板）  
`notify_pm`: **true**

## 运营定档对齐

| 项 | 结果 |
|----|------|
| 结构 | Hero **仅** 1 主 + 1 次（无第三同质 Shop） |
| 主 CTA 中文 / 英文 | `浏览精选` / `Shop Featured` |
| 主 CTA 路径 | `#homepage-featured`（Featured 货架锚点） |
| 次 CTA 中文 / 英文 | `按场景选` / `Shop by Occasion` |
| 次 CTA 路径 | 站内 `/category/hanfu/occasion`（礼宴·旅拍·日常等场景树；**非**尺码助手） |
| 视觉层级 | 主 `w-button-primary` 实心；次 `w-button-outline` 描边 |
| `$49` 门槛 | **未改**（本单勿动） |
| Wave-1 / P1-04 / P2-05/06/07 | **未抢改** |

## 改动摘要

1. `app/code/Weline/Theme/view/theme/frontend/widgets/banner/hero-slider/default.phtml`  
   - 默认帧 CTA：`浏览精选` → `#homepage-featured`；`按场景选` → `/category/hanfu/occasion`  
   - 渲染 `slide-cta-group`（主+次）；hash 锚点不走 `getUrl`  
   - 运营旧帧「浏览系列 / `/products`」归一到顾问主路径，并补次 CTA  
2. `app/code/Weline/Theme/view/theme/frontend/widgets/product/featured-products/default.phtml`  
   - 根节点补 `id="homepage-featured"`（锚点可达）  
3. `app/design/Weline/hanfu/frontend/widgets/banner/hero-slider/default.phtml`  
   - 与默认层 CTA 意图同步（design 防漂移；现网验收面走默认 Theme Hero）  
4. `app/code/Weline/Theme/i18n/zh_Hans_CN.csv` + `en_US.csv`  
   - 新增源串：`浏览精选`、`按场景选`（英：`Shop Featured` / `Shop by Occasion`）  
5. 契约：`ThemeHanfuHomepageDefaultsContractTest` + `HanfuStorefrontLocaleContractTest` 同步断言  

未改：`generated/`、包邮门槛、信任条/Newsletter/迷你车、Wave-1 货架。

## 验收

验收面：`https://p05113ef3.test.weline.com:9555/`

| 探针 | 主 CTA | 次 CTA | 锚点 / 场景链 |
|------|--------|--------|----------------|
| `/zh_Hans_CN/`（nocache） | `浏览精选` → `#homepage-featured` | `按场景选` → `/zh_Hans_CN/category/hanfu/occasion` | `id="homepage-featured"` 存在；场景 URL HTTP **200** |
| `/`（currentLang=`en_US`） | `Shop Featured` | `Shop by Occasion` | 同上意图 |

方式：`curl` + `Cache-Control: no-cache`（Hero 源模板即时生效）。Cursor ide-browser 本回合 tab/CDP 无法稳定开页（与 Wave-1 主题席同类限制），以 curl 禁缓存 HTML 为准。  
`i18n:collect`：本机有并行席 `i18n:collect` 占锁；CSV 已落盘，店面英文化走 `translateDefaultCopy` 英文回退已验 PASS；collect 解阻后由 PM/翻译席补跑即可。

契约：`ThemeHanfuHomepageDefaultsContractTest::testDefaultHero*` → **OK (2 tests, 26 assertions)**。

## related_web_urls

- [首页（验收面）](https://p05113ef3.test.weline.com:9555/)
- [中文首页](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/)
- [场景导购（次 CTA 目标）](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/category/hanfu/occasion)

## 升级项目经理

`notify_pm: true` — `@项目经理：WO-HP-P2-08 主题席已 closed（Hero 1 主+1 次对齐顾问 brief），请 DoD 检查并安排顾问运营复审 / Wave-2 汇审。`
