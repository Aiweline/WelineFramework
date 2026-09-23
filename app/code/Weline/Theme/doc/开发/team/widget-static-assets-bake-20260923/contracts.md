# contracts — widget-static-assets-bake-20260923

| ID | 契约 | 验收 |
|----|------|------|
| UC-attr | `w:widget` 支持 `layout-source` / `source`（逗号分隔 `Vendor_Module::path`） | Taglib attr + 节点字段 |
| UC-meta | `@widget.layout_source` / `@widget.source` 进注册表；布局未写属性时 bake 回落 | WidgetTemplateParser + Collector |
| UC-bake | Materializer 写 `page-assets.json` / `chrome-assets.json`；与 BakeCoordinator / rebakeAfterInjectionCollect 同闸 | 磁盘 sidecar + UT |
| UC-head | 店面 head 排放 layout 桶先于 source 桶；同 URL 去重 | HTML 含 link/script + marker |
| UC-delta | 请求仅合并动态 Δ；published 静态闭包不重扫 | 设计+代码注释 |
| UC-layout-gate | content 等非白名单 `layout-source` → 契约 FAIL（测） | ContractTest |
| UC-no-shell-weld | 禁焊进 shell.phtml / chrome.rendered | surfaces 审查 |
| UC-js-exception | bake head `.js` 为 `theme_js_module_declare_only` 书面例外 | 硬规则文档 |

硬禁：拆壳、假 HIT、design 盖 theme.css、平行 ModuleLoader。
