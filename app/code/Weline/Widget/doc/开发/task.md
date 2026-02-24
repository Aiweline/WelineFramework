# Widget 模块任务（媒体选择与分辨率配置）

- [x] 新增 MediaImageType 参数类型
- [x] ParamTypeRenderer 注册 media_image
- [x] ArrayType 支持 media_image 并传入 fieldId
- [x] widget-param-types.js：initMediaImagePicker、__INDEX__ 替换、新增项后初始化

# Widget 模块任务（语义化 ParamSchema 架构）

- [x] 新增 ParamSchemaScanner：扫描各模块 Ui/ParamSchema/*.php
- [x] 新增 ParamSchemaRegistry：getRegistry / refresh / expandParams / saveRegistry
- [x] WidgetConfigService 注入 ParamSchemaRegistry，getParamDefinitions 中 expandParams
- [x] WidgetConfigService::getRegisteredTypes 合并 schema types
- [x] widget:refresh 命令增加 ParamSchemaRegistry->refresh()
- [x] SetupUpgradeAfter Observer 增加 ParamSchemaRegistry->refresh()，日志改 Env::log_error
- [x] ModuleUpgradeAfter Observer 增加 ParamSchemaRegistry->refresh()，日志改 Env::log_error
- [x] ModuleInstallAfter Observer 增加 ParamSchemaRegistry->refresh()，日志改 Env::log_error
