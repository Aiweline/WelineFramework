# 主题开发工程师 — construction P5-fix-chrome（O1）

- seat: Team:主题开发工程师:
- date: 2026-09-23
- team: `wls-perf-regression-20260923`
- wave: construction · **P5-fix-chrome（O1）**
- work_mode: `theme_module_runtime`
- area: `frontend`
- against: contracts.md · **性能检查-review.md §6 O1** · channel msg-5（架构师冻结）+ msg-10（PM 组队）· 统一缓存范围与性能优化.md
- result: **closed**
- reload: **未由本席执行**（禁私自 reload；delay 未砍→0）

---

## 声明

| 项 | 值 |
|----|-----|
| work_mode | theme_module_runtime |
| area | frontend |
| 四层 | 本波不改 layout/partial/component/widget 皮肤文件（仅 PHP 种袋） |
| 公共库 | N/A |
| 禁 fetch / JS declare-only | N/A |
| 必装永远存在 | 未改 injection |

---

## 复审失败点 → 本席落地

| 复审证据 | 本席动作 |
|----------|----------|
| `chrome_rendered:miss` 仍烧 pre≈9522ms / peer≈7976ms | leaf scope 无 bake → seed **scope 回落** + **disk-only**；miss 改 **诚实 []** 短路径 |
| Scope 键 `default.__store__.__channel__` miss | `ensureStorefrontScopeIdentity` + `resolveStorageScope` 回落均对齐 channel leaf；seed 回落含 `__website__`（盘上 chrome 所在） |
| 禁假 HIT / 禁空串 HTML HIT | 不 remember 空串 chrome.rendered；`[]` 仅为 slot 诚实 miss 标记 ≠ FPC HIT |
| 禁空烧重投影 | miss **不再**调 `primePublishedChromeSlotProjectionHotCache`（会 build/include） |
| 禁私自 reload / 砍 delay | **未做** |

---

## 变更清单（dirty-load 后写入）

| 路径 | 说明 |
|------|------|
| `Theme/Service/LayoutEntity/ThemeLayoutEntityChrome.php` | `seedPublishedHotCacheEager`：`publishedChromeSeedScopes` 回落；仅 `readRenderedCache` |
| `Theme/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php` | 新增 `rememberHonestEmptyChromeSlotProjection` |
| `Theme/Service/StorefrontHotCacheBagSeeder.php` | miss→诚实 []；HIT 才 heavy prime；scope 回落对齐 |
| `Theme/test/.../StorefrontHotCacheBagWarmupProviderContractTest.php` | O1 契约 |
| `Theme/etc/module.php` | **2.2.601 → 2.2.602** |

**未做**：砍 delay；假 HIT；平行袋；改 Framework/Product；nginx:reload；generated/view/tpl。

---

## 验收 / 自测

- UT：`php bin/w phpunit:run --module=Weline_Theme --name=StorefrontHotCacheBagWarmupProviderContractTest`（本席跑）
- 运行时墙钟：需 **PM 再批受控 reload** → 性能再审（本席禁自 reload）

---

## result 字段

| field | value |
|-------|-------|
| result | **closed** |
| notify_pm | **true** |
| theme_version | **2.2.602** |
| fake_hit | none |
| parallel_bag | none |
| delay_changed | false |
| claim_sla | false |

---

## related_web_urls

- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/products

（诊断直连非交付：`http://p05113ef3.test.weline.com:19655/`）

---

@项目经理：本席已交付/上报（P5-fix-chrome O1 **closed**），请检查并更新 SESSION；再批 reload 后交性能复审。
