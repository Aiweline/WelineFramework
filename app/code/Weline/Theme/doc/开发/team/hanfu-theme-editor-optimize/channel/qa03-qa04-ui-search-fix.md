# QA-03 / QA-04 · Theme+Search 席修复证据

日期：2026-09-23  
席位：Team:前端/Theme+Search  
仓库：`/Users/weline/Project/Official/框架`

## QA-04 · `get(...)?.close is not a function`

### 根因
- `weline-ui.js` 不完整组件（缺 trigger/panel 等）原先 `return {}`，`mountElement` 又以 `factory() || {}` 落库。
- `UI.get` 返回空对象（truthy），可选链 `?.close` 只跳过 null/undefined，仍会调用非函数 → `close is not a function`。
- 触发面：`closeTransientSurfaces`（pagehide / bfcache）、`language-switcher` 开语言申请弹层前关 menu、Escape 关 dialog/drawer。

### 改动
| 文件 | 要点 |
|------|------|
| `Theme/view/ui/js/weline-ui.js` | 不完整 factory → `null`；空对象不注册；`closeTransientSurfaces` / Escape 用 `typeof api?.close === 'function'` |
| `Theme/view/ui/js/language-switcher.js` | 同上 typeof 守卫 |
| `I18n/view/statics/js/language-switcher.js` | 同上（并行静态副本） |

### 验证
1. 控制台：打开带语言切换 / 菜单的店面页，触发 pagehide 或点「申请语言」；不应再出现 `close is not a function`。
2. 契约自检：`UI.get(el,'menu')` 要么 `null`，要么含可调用 `close`。

## QA-03 · 热搜「汉服配饰」无商品

### 先验（本机店面 `https://p05113ef3.test.weline.com:9555`）
| 词 | 结果 |
|----|------|
| `汉服配饰` | 共 2 条；sections=`blog,faq`；**无 product** |
| `马面裙` / `明制汉服` / `齐胸襦裙` / `披帛` | 有 product |
| `发簪` | 共 0 条 |

结论：投影/索引对「汉服配饰」无商品命中；博客/FAQ 同题仍抬高 hitCount；`SearchHubService::searchAll` 对空 section `continue` 使商品栏消失。

### 改动
| 文件 | 要点 |
|------|------|
| `Theme/Helper/HeaderCommerceData.php` | 默认热词末位 `汉服配饰` → **`披帛`**（有货、配饰向） |
| `Search/Service/SearchHubService.php` | type=all 非补全时，空 **product** 仍写入 `sections['product']=[]` |
| `Search/view/templates/frontend/index.phtml` | 空商品节明示「商品 0 条」+ 换词提示（`data-testid=storefront-search-product-empty`） |
| `Search/.../layouts/search/default.phtml` | `.storefront-search__section-empty` 轻样式 |
| `Search/i18n/{zh_Hans_CN,en_US}.csv` | 新文案中英 |
| `Theme/i18n/{zh_Hans_CN,en_US}.csv` | `披帛` 中英 |
| 单测 / 指南 | `HeaderCommerceDataHanfuDefaultsTest`、`SearchIndexTemplateContractTest`、`HanfuStorefrontLocaleContractTest`（英译）、`Partials配置系统使用指南.md` |

### 验证（已跑）
1. HTTP `…/search?q=汉服配饰` → sections=`product,blog,faq`；`storefront-search-product-empty` +「商品 0 条」；meta 仍「共 2 条」。
2. 首页热搜 demo 词含「披帛」，不再含「汉服配饰」。
3. UT：`HeaderCommerceDataHanfuDefaultsTest` PASS；`SearchIndexTemplateContractTest` PASS（需 `--bootstrap app/bootstrap.php`）。
4. `php bin/w i18n:collect` 已执行。

## 未竟
- 分类导航 / category-menu demo 仍链 `/search?q=汉服配饰`（非本席热搜默认词）；配饰类目投影补货或改分类深链未做。
- 未重建商品搜索索引；仅换有货热词 + 空商品 UI。
- `HanfuStorefrontLocaleContractTest::testHanfuStorefrontCopy…` 在脏树上因既有「日常通勤」英译漂移失败（Expected `Everyday & Work` / Actual `Everyday commute`），与本次 `披帛` 无关，未擅改。
- Browser 端到端截图 / site_error 清零留给汇审/WB。
