# Weline_Theme 任务清单

**最后更新**: 2026-03-20

> 任务清单按当前代码实际状态整理。
> 历史方案和实现总结只保留参考价值，当前执行以本文件和 `plan.md` 为准。

## 已确认完成

- [x] 核对 `ThemeData` 的部件参数 API 与多语言读写能力
- [x] 核对 `ThemeEditor` 中 `getInstalledLocales()`、`getWidgetConfig()`、`postSaveWidgetConfig()` 的实际存在
- [x] 核对 `theme-editor.js` 中语言切换、多语言编辑入口和字段标识逻辑
- [x] 核对 `ThemeConfigManager`、`PreviewManager`、`ConfigLoader` 已落地
- [x] 核对现有自动化测试覆盖预览/更新基础链路
- [x] 重写模块 `doc/开发/plan.md`
- [x] 重写模块 `doc/开发/task.md`
- [x] 给历史方案文档增加状态说明和统一入口指向
- [x] 把旧的 `w_msg()` 模块计划降级为历史已完成事项

## 待补齐

- [ ] 盘点并补齐部件配置相关 i18n 词条缺口
- [ ] 增加 `getInstalledLocales()` 自动化测试
- [ ] 增加 `getWidgetConfig(locale)` 自动化测试
- [ ] 增加 `postSaveWidgetConfig(locale)` 自动化测试
- [ ] 增加“可翻译字段 / 非可翻译字段”行为测试
- [ ] 复跑 `widget-config-i18n-testing.md` 中的手工验证并回填结论
- [ ] 清理 `需求文档-完整版.md` 中仍停留在开发期的待办表述

## 历史已完成事项

- [x] 后台 `Weline.Message` 模块与 `window.w_msg()` 已交付
- [x] 主题编辑器预览局部刷新基础链路已交付
- [x] 配置面板视觉增强与多语言入口已交付

## 当前优先级

1. i18n 缺口盘点
2. locale 相关自动化测试
3. 手工验证与历史文档瘦身
