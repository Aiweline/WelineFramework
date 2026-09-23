# channel — WO-HP-P2-05 主题席回执

日期：2026-09-22  
席位：`Team:主题开发工程师:`（协同部件落点：同席一施工智能体内嵌 `trust-badges` 默认参数）  
工单：`WO-HP-P2-05`（信任条上移至首屏二折内）  
`work_mode`: **`default_theme`**  
跨 mode justification：验收面为 hanfu design 覆盖首页壳；同波同步 `design_theme` 路径 `app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml` 槽序与紧凑样式，否则店面读不到 Theme 默认层改动。  
`notify_pm`: **true**

## 运营定档对齐

| 项 | 结果 |
|----|------|
| 位置 | Hero 下方、Featured 上方（`homepage-trust`） |
| 形态 | 一行三枚：`满 $49 包邮` · `退款有保障` · `安全支付` |
| 门槛 | **`$49` USD**（Wave-1 定档；无 `¥299`） |
| 页底 | `footer-above` 保留同口径精简三枚，去掉 24/7 冗长第四枚 |

## 改动摘要

### 代码 / i18n（仓库）

1. `app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml`  
   - `homepage-trust` 上移至 Hero 后；else 内嵌紧凑 `trust-badges`（3 枚 / columns=3）。
2. `app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml`  
   - 同上槽序 + `homepage-section--trust-strip` 紧凑 Token 样式。
3. `app/code/Weline/Theme/view/theme/frontend/widgets/content/trust-badges/default.phtml`  
   - `free-shipping` 标题/描述源串改为 `满 $49 包邮`。
4. `app/code/Weline/Theme/view/theme/frontend/partials/footer/default.phtml`  
   - 页底默认信任条改为同三枚精简参数。
5. `app/code/Weline/Theme/i18n/zh_Hans_CN.csv` / `en_US.csv`  
   - 新增 `满 $49 包邮` → `Free shipping over $49`。
6. 契约：`FooterAboveTrustBadgesContractTest`、`ThemeShellLayoutDefaultWidgetsContractTest`、`HanfuStorefrontLocaleContractTest`。
7. `app/code/Weline/Theme/etc/module.php`：`2.2.554` → `2.2.555`。

### 运行时（本机库 + 烘焙实体；非 git）

8. `w_theme_scope_release` `release_id=270`（theme_id=3 homepage）  
   - 修复 `homepage-trust` 节点被 hero-slider 字段污染的 config → 紧凑三枚 `$49`。
9. `var/runtime/theme-layout-entities/3/.../r270/page-config.json` 同步。
10. `w_i18n_dictionary` / `w_i18n_locale_dictionary`：`满 $49 包邮` zh/en。
11. `ThemeRuntimeCacheCleaner::clearAllThemeRelatedCaches(theme_id=1|3)`。

## 未做 / 边界

- 未抢 P1-04 locale 大改、P2-06 Newsletter、P2-07 迷你车、P2-08 Hero CTA。
- 门槛仍为展示硬编码（与 Wave-1 备注一致）；后续应绑站点币种 + 生效包邮规则。
- Cursor ide-browser 本回合无法开 tab（工具报无 tab）；以 curl 禁缓存自验为准。

## 验收

验收面：`https://p05113ef3.test.weline.com:9555/`（及 `/zh_Hans_CN/`）

| 探针 | 信任条位置 | 三枚文案 | `¥299` |
|------|------------|----------|--------|
| `/` | Hero 后、Featured 前；`data-widget-code="trust-badges"`×2（首屏+页底） | `Free shipping over $49` / Refunds… / Secure payment | 0 |
| `/zh_Hans_CN/` | 同上 | `满 $49 包邮` / `退款有保障` / `安全支付` | 0 |

方式：`curl` + `Cache-Control: no-cache`（清 Theme/FPC 后复验）。

## related_web_urls

- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/zh_Hans_CN/

## 升级项目经理

`notify_pm: true` — `@项目经理：WO-HP-P2-05 主题席已 closed，请 DoD 检查并安排顾问运营复审 / Wave-2 汇审。`
