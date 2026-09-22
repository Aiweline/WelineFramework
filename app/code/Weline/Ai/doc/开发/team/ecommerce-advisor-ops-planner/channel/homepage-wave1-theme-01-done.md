# channel — WO-HP-P1-01 主题席回执

日期：2026-09-22  
席位：`Team:主题开发工程师:`  
工单：`WO-HP-P1-01`（币种 / 包邮门槛展示对齐）  
`work_mode`: **`default_theme`**（默认 Theme 促销默认文案 + Theme i18n；店面生效依赖已发布 layout 实体 sidecar 与词典）  
`notify_pm`: **true**

## 运营定档对齐

| 项 | 结果 |
|----|------|
| 展示币种 | USD（`$`） |
| 门槛 | **`$49`** |
| 中文促销条 | `满 $49 包邮 · 新品上架` |
| 英文促销条 | `Free shipping over $49 · New curated styles` |
| `¥299` 残留 | 首页促销条 **0** |

## 改动路径

### 代码 / i18n（仓库）

1. `app/code/Weline/Theme/view/theme/frontend/widgets/banner/promo-banner/default.phtml`  
   - 默认促销源串：`满 $49 包邮 · 新品上架`
2. `app/code/Weline/Theme/i18n/zh_Hans_CN.csv`  
   - `满 $49 包邮 · 新品上架`  
   - `欢迎光临！满 $49 包邮 · 新客首单立减`（去 ¥299）
3. `app/code/Weline/Theme/i18n/en_US.csv`  
   - `满 $49 包邮 · 新品上架` → `Free shipping over $49 · New curated styles`  
   - 欢迎串对应英文同步 `$49`
4. `generated/widgets.php`  
   - promo-banner 默认参数同步为 `$49` 源串（登记产物）

### 运行时配置（本机库 + 烘焙实体；非 git）

5. `w_theme_scope_release`（含 `release_id=284` 等）  
   - 布局节点 `promo-banner` 源串：`云裳汉服 · 满 ¥299…` → `满 $49 包邮 · 新品上架`
6. `w_theme_scope_patch` / `w_theme_layout_version` 同串替换  
7. `w_i18n_dictionary` + `w_i18n_locale_dictionary`（zh_Hans_CN / en_US）新源串译文  
8. `var/runtime/theme-layout-entities/**/page-config.json`（含 `…/r284/page-config.json`）  
   - 烘焙 sidecar 同步新源串（此前仅改 DB 仍被 HotCache/实体吃旧值）  
9. `ThemeRuntimeCacheCleaner::clearAllThemeRelatedCaches(theme_id=1|3)` 使店面读到新 sidecar

## 未做 / 后续备注（给 PM / 顾问）

- **门槛仍为硬编码展示文案**，未绑定站点币种配置或 `FreeShippingRule`（种子已有 `SEED_FREE_49`，当前默认启用的是 `SEED_FREE_99`）。  
  **后续应**：促销条/迷你购物车进度从「站点展示币种 + 生效包邮规则门槛」同源生成，禁止再手写 `$`/`¥` 数字。
- 未动三货架（P1-02）、评价/UGC（P1-03）。
- 全语种词典里旧词 `云裳汉服 · 满 ¥299…` 仍可能残留；本波仅保证默认站首页中英展示与 Theme 中英 CSV。全 locale 清扫属 P1-04。

## 验收

验收面：`https://p05113ef3.test.weline.com:9555/`（及 `/zh_Hans_CN/`、`/en_US/`）

| 探针 | 促销条文案 | `¥299` | 价签样例 |
|------|------------|--------|----------|
| `/` | `Free shipping over $49 · New curated styles` | 0 | `$20.11` 等 `$` |
| `/zh_Hans_CN/` | `满 $49 包邮 · 新品上架` | 0 | 同左 `$` |
| `/en_US/` | `Free shipping over $49 · New curated styles` | 0 | 同左 `$` |

方式：`curl` + `Cache-Control: no-cache`（FPC MISS 后复验）。Cursor ide-browser 本回合 `browser_navigate` 无法开 tab（工具报无 tab），以 curl 禁缓存结果为准。

## 升级项目经理

`notify_pm: true` — `@项目经理：WO-HP-P1-01 主题席已 closed，请 DoD 检查并安排顾问运营复审。`
