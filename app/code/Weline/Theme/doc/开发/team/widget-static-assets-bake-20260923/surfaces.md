# surfaces — widget-static-assets-bake-20260923

此表记录旧方案，已被 PHTML 固化方案替代。当前资源加载复用正常模板流程，不生成资源 JSON 侧车；以下侧车写入步骤不再执行。

## 写路径

| # | 表面 | 变更 |
|---|------|------|
| S1 | `Widget/Taglib/Widget.php` | attr: `layout-source`, `source`；写入节点 |
| S2 | `ThemeScopedLayoutWriteService::addWidget` | 接受并落盘 `layout_source` / `source` |
| S3 | `TemplateInlineWidgetMerger` | 模板内嵌属性进节点 |
| S4 | `ThemeLayoutEntityPaths` | 仅保留派生 PHTML 路径；旧资源侧车路径方法已删除 |
| S5 | `ThemeLayoutEntityConfigStore` | write/read assets sidecar |
| S6 | `ThemeLayoutEntityAssetCollector` | 从节点+注册表收集、白名单、去重 |
| S7 | `ThemeLayoutEntityMaterializer` | materialize 后写 assets |
| S8 | `ThemeLayoutEntityBakeCoordinator` | rebake / config-only 同步 assets |
| S9 | `ThemeLayoutStorefrontHeadAssets` | 店面 head HTML |
| S10 | `partials/head/page.phtml`（或 hook） | 调用 HeadAssets |

## 文档（Wave0 已做）

权威：`部件静态资源固化规范.md`；硬规则 `widget_static_assets_bake_to_head`。

## work_mode

机制 PHP：`theme_module_runtime`；改 Theme partial：`default_theme`。
