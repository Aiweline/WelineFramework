# 模块前端主题合规扫描（2/49 模块有命中）

- 命中总数：**5**（P0 5 / P1 0 / P2 0）
- 涉及文件：5

| 模块 | 命中 | P0 | P1 | P2 | 文件 | 主要类型 |
|---|---:|---:|---:|---:|---:|---|
| Weline/TwoFactorAuth | 3 | 3 | 0 | 0 | 3 | inline_script_exec×3 |
| Weline/Ai | 2 | 2 | 0 | 0 | 2 | inline_script_exec×2 |

## 范围（机器可读配置：`module-frontend-theme-scope.json`）

- 扫描模式：`frontend_consumption`
- 排除模块：25 个 —— Acl、Agent、Api、CKEditorEditorManager、CacheManager、Component、Dashboard、Dropship、Eav、ElFinderFileManager、FileManager、Frontend、Maintenance、MediaManager、Queue、RdpWrapper、SampleModule、Seo、Server、SessionManager、SiteSetupAssistant、SystemConfig、Taglib、TranslationService、WidgetDemo
- 排除依据：后台 area（合同禁止）/ prototype / 第三方 app 壳 / 框架提供方

| 模块 | 命中 | 路径白名单 |
|---|---:|---|
| Weline/DataTable | 0 | Weline/DataTable/view/templates/frontend/<br>Weline/DataTable/view/statics/css/ |
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
