# Widget 模块任务（媒体选择与分辨率配置）

## REQ-THEME-0001（关联变更，1.0.1）

- [x] `widget-param-types.css` 改为只消费前后台 canonical 语义 Token，参数表单的 surface/text/border/input/focus/status 自动适配 system/light/dark。
- [x] 保留透明值的棋盘格作为功能性展示，但其棋盘颜色同样使用语义边框 Token。
- [x] 同步 `etc/module.php` 至 `1.0.1`；这是 Widget 对 REQ-THEME-0001 的关联版本，不改变 Theme `1.0.8`、Backend `1.4.1`、Admin `1.0.2` 的目标版本。

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
