# Widget 模块：媒体选择与分辨率配置

本模块子计划，总计划见 [.cursor/plans/媒体选择与分辨率配置_676aad06.plan.md](../../../../.cursor/plans/媒体选择与分辨率配置_676aad06.plan.md)。

## 目标

- 新增参数类型 `media_image`：从媒体库选择图片，支持 `media_options`（default_directory、recommend_width/height）。
- ArrayType 支持 item_schema 中 `media_image` 字段，并传入 fieldId 生成唯一 input id。
- 不依赖 FileManager/Theme，仅通过 data-* 输出 options，由宿主注入 connector 并打开选择器。

## 已完成

- [x] 新增 `Ui/ParamType/MediaImageType.php`，输出预览 + 隐藏 input + 按钮（data-default-dir、data-recommend-w/h、data-target）。
- [x] `ParamTypeRenderer` 注册 `media_image` => MediaImageType。
- [x] `ArrayType::renderItemField` 增加 `media_image` 分支，签名增加 `$fieldId`，item 内 id 为 `fieldId_index_fieldKey`。
- [x] `widget-param-types.js`：`initMediaImagePicker`（打开选择器模态框、回填后更新预览、清除按钮），`buildItemHtml` 中替换 `__INDEX__` 以修复 array 项内 id，新增项后调用 `initMediaImagePicker(wrapper)`。

## 涉及文件

- `app/code/Weline/Widget/Ui/ParamType/MediaImageType.php`（新增）
- `app/code/Weline/Widget/Service/ParamTypeRenderer.php`
- `app/code/Weline/Widget/Ui/ParamType/ArrayType.php`
- `app/code/Weline/Widget/view/statics/js/widget-param-types.js`

---

## 语义化 Param Type 与 ParamSchema 注册表

总计划见 [.cursor/plans/widget_语义化_paramschema_架构.plan.md](../../../../.cursor/plans/widget_语义化_paramschema_架构_c2eaa33f.plan.md)。

### 目标

- 模板侧不再写 `type="array"` + 内联 schema，改为语义化 `type="banner_items"`，schema 由架构统一提供。
- 由 Weline_Widget 在收集阶段扫描所有已启用模块的 `Ui/ParamSchema/*.php`，一次性收集并写入 `generated/param_schemas.php`。
- 运行时 `WidgetConfigService::getParamDefinitions()` 自动展开语义化 type 为 `array` + `item_schema`，ParamTypeRenderer 与 ArrayType 无需改动。

### 已完成

- [x] 新增 `Service/ParamSchemaScanner.php`：扫描所有已启用模块的 `Ui/ParamSchema/*.php`，仅 CLI 执行。
- [x] 新增 `Service/ParamSchemaRegistry.php`：管理 `generated/param_schemas.php` 的读写与缓存；提供 `expandParams()` 展开语义化 type。
- [x] `WidgetConfigService` 注入 `ParamSchemaRegistry`，`getParamDefinitions()` 返回前 `expandParams()`，`getRegisteredTypes()` 合并 schema types。
- [x] `Console/Widget/Refresh.php` 在 widget 注册表刷新后同步刷新 ParamSchema 注册表。
- [x] 三个 Observer（SetupUpgradeAfter / ModuleUpgradeAfter / ModuleInstallAfter）同步刷新 ParamSchema，日志改用 `Env::log_error()`。

### 涉及文件

- `app/code/Weline/Widget/Service/ParamSchemaScanner.php`（新增）
- `app/code/Weline/Widget/Service/ParamSchemaRegistry.php`（新增）
- `app/code/Weline/Widget/Service/WidgetConfigService.php`
- `app/code/Weline/Widget/Console/Widget/Refresh.php`
- `app/code/Weline/Widget/Observer/SetupUpgradeAfter.php`
- `app/code/Weline/Widget/Observer/ModuleUpgradeAfter.php`
- `app/code/Weline/Widget/Observer/ModuleInstallAfter.php`
