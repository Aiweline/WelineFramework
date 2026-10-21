# 模块前端主题合规扫描（34/49 模块有命中）

- 命中总数：**649**（P0 24 / P1 567 / P2 58）
- 涉及文件：140

| 模块 | 命中 | P0 | P1 | P2 | 文件 | 主要类型 |
|---|---:|---:|---:|---:|---:|---|
| Weline/Payment | 24 | 5 | 18 | 1 | 8 | hard_color_hex×9, inline_script_exec×5, php_i18n_call×5, private_token_def×2 |
| Weline/Customer | 89 | 4 | 83 | 2 | 20 | important×30, hard_color_func×27, php_i18n_call×20, hard_color_hex×6 |
| Weline/CustomerService | 21 | 4 | 12 | 5 | 4 | hard_color_func×7, inline_script_exec×4, inline_style×4, hard_color_hex×2 |
| Weline/TwoFactorAuth | 39 | 3 | 34 | 2 | 5 | hard_color_hex×17, php_i18n_call×8, hard_color_func×4, hard_max_width_px×3 |
| Weline/Ai | 45 | 2 | 39 | 4 | 3 | php_i18n_call×18, hard_color_func×12, hard_color_hex×9, inline_style×3 |
| Weline/Shipping | 21 | 2 | 19 | 0 | 9 | hard_color_hex×7, hard_color_func×6, php_i18n_call×6, inline_script_exec×2 |
| Weline/Product | 62 | 1 | 51 | 10 | 17 | php_i18n_call×44, hard_size×5, inline_style×5, hard_color_func×4 |
| Weline/Installer | 47 | 1 | 46 | 0 | 2 | php_i18n_call×25, hard_color_func×8, hard_color_hex×7, private_token_def×5 |
| Weline/B2B | 17 | 1 | 15 | 1 | 5 | php_i18n_call×8, important×7, inline_style×1, inline_script_exec×1 |
| Weline/Cart | 9 | 1 | 8 | 0 | 3 | hard_color_hex×3, important×3, private_token_def×1, inline_script_exec×1 |
| Weline/Visitor | 42 | 0 | 40 | 2 | 4 | private_token_def×19, hard_color_func×10, hard_color_hex×9, hard_size×1 |
| Weline/DataTable | 39 | 0 | 38 | 1 | 2 | hard_color_hex×35, hard_color_func×3, hard_size×1 |
| Weline/Checkout | 20 | 0 | 20 | 0 | 2 | php_i18n_call×20 |
| Weline/Inquiry | 19 | 0 | 19 | 0 | 2 | hard_color_func×9, hard_color_hex×6, private_token_def×3, important×1 |
| Weline/Order | 19 | 0 | 19 | 0 | 4 | hard_color_func×12, hard_color_hex×3, important×3, php_i18n_call×1 |
| Weline/Promotion | 17 | 0 | 15 | 2 | 3 | hard_color_hex×7, private_token_def×4, hard_color_func×3, hard_size×2 |
| Weline/I18n | 14 | 0 | 12 | 2 | 6 | php_i18n_call×6, js_fetch_direct×4, hard_color_hex×2, js_observe_direct×2 |
| Weline/Multipass | 11 | 0 | 11 | 0 | 3 | hard_color_hex×8, private_token_def×2, php_i18n_call×1 |
| Weline/Mail | 12 | 0 | 10 | 2 | 2 | hard_color_hex×5, php_i18n_call×4, inline_style×2, hard_color_func×1 |
| Weline/Social | 12 | 0 | 9 | 3 | 2 | php_i18n_call×8, inline_style×3, hard_max_width_px×1 |
| Weline/Blog | 8 | 0 | 8 | 0 | 4 | php_i18n_call×7, hard_color_hex×1 |
| Weline/Compare | 5 | 0 | 5 | 0 | 2 | hard_color_func×4, php_i18n_call×1 |
| Weline/Search | 5 | 0 | 5 | 0 | 2 | hard_color_hex×3, php_i18n_call×1, hard_color_func×1 |
| Weline/HelpPay | 16 | 0 | 4 | 12 | 7 | inline_style×12, important×4 |
| Weline/Captcha | 6 | 0 | 4 | 2 | 2 | php_i18n_call×3, hard_size×2, hard_color_hex×1 |
| Weline/Newsletter | 6 | 0 | 4 | 2 | 3 | important×4, inline_style×2 |
| Weline/Consent | 4 | 0 | 4 | 0 | 1 | hard_color_hex×4 |
| Weline/Demo | 5 | 0 | 3 | 2 | 3 | hard_color_hex×3, inline_style×2 |
| Weline/StoreMusic | 3 | 0 | 3 | 0 | 2 | important×3 |
| Weline/Wishlist | 3 | 0 | 3 | 0 | 2 | php_i18n_call×2, important×1 |
| Weline/Marketing | 3 | 0 | 2 | 1 | 2 | hard_color_hex×2, inline_style×1 |
| Weline/RecentlyViewed | 2 | 0 | 2 | 0 | 1 | php_i18n_call×2 |
| Weline/Affiliate | 2 | 0 | 1 | 1 | 2 | hard_color_func×1, inline_style×1 |
| Weline/Faq | 2 | 0 | 1 | 1 | 1 | hard_color_hex×1, hard_size×1 |

## 范围（机器可读配置：`module-frontend-theme-scope.json`）

- 扫描模式：`frontend_consumption`
- 排除模块：25 个 —— Acl、Agent、Api、CKEditorEditorManager、CacheManager、Component、Dashboard、Dropship、Eav、ElFinderFileManager、FileManager、Frontend、Maintenance、MediaManager、Queue、RdpWrapper、SampleModule、Seo、Server、SessionManager、SiteSetupAssistant、SystemConfig、Taglib、TranslationService、WidgetDemo
- 排除依据：后台 area（合同禁止）/ prototype / 第三方 app 壳 / 框架提供方

| 模块 | 命中 | 路径白名单 |
|---|---:|---|
| Weline/DataTable | 39 | Weline/DataTable/view/templates/frontend/<br>Weline/DataTable/view/statics/css/ |
| Weline/Index | 0 | Weline/Index/view/templates/Index.phtml<br>Weline/Index/view/statics/js/ |
| Weline/Widget | 0 | Weline/Widget/view/templates/widgets/ |

## 例外白名单（不计违规，依据 Theme 文档）

| 例外 | 依据 |
|---|---|
| 纸面钉 `#0f1111` / `#565959` / `#666666` 绑 `--weline-chrome-body-text*` / `--amz-drawer-text` / `*paper-text` | theme-css-variables-only.md §8 |
| `@media` 断点字面量 | theme-css-variables-only.md §7 |
| `[hidden] { display: none !important }` | theme-css-variables-only.md 例外表 |
| `@media (prefers-reduced-motion: reduce)` 内 `!important` | theme-css-variables-only.md 例外表 |
| 部件 bake 通道 `<script ... data-weline-widget-asset / data-weline-source-position>` | 前端JS模块加载规范.md §1.5 |

## 用法

```bash
python3 app/code/Weline/Theme/bin/module-frontend-theme-audit.py                 # 汇总
python3 app/code/Weline/Theme/bin/module-frontend-theme-audit.py --module Weline_Compare
python3 app/code/Weline/Theme/bin/module-frontend-theme-audit.py --json
python3 app/code/Weline/Theme/bin/module-frontend-theme-audit.py --baseline app/code/Weline/Theme/test/fixtures/module-frontend-theme-baseline.json
python3 app/code/Weline/Theme/bin/module-frontend-theme-audit.py --write-baseline app/code/Weline/Theme/test/fixtures/module-frontend-theme-baseline.json  # 迁移后下调
```
