# Weline_Theme 主题编辑器与配置系统收口计划

**状态**: 收口中（status: in_progress）  
**完成度**: 80%  
**最后更新**: 2026-03-20

> 本计划按当前代码实际状态重建，替代旧的 `w_msg()` 模块子计划。
> 当前任务跟踪以 `doc/开发/plan.md` 与 `doc/开发/task.md` 为准。
> 历史参考文档：`doc/需求文档-完整版.md`、`doc/widget-config-enhancement-plan.md`、`doc/widget-config-i18n-implementation-summary.md`、`doc/widget-config-i18n-testing.md`、`doc/主题配置方案.md`、`doc/主题配置系统实施总结.md`、`doc/widget-config-ui-beautification.md`

## 代码现状结论

### 1. 已实现的核心能力

- 部件配置多语言参数访问能力已落地：`Helper/ThemeData.php`
  - `getWidgetParamDefinitions()`
  - `getWidgetParamDefinitionsWithRegistry()`
  - `getWidgetParam()`
  - `setWidgetParam()`
  - `getWidgetParams()`
  - `setWidgetParams()`
- 主题编辑器已提供多语言相关接口：`Controller/Backend/ThemeEditor.php`
  - `getInstalledLocales()`
  - `getWidgetConfig()`
  - `postSaveWidgetConfig()`
- 配置面板前端多语言与视觉增强已落地：`view/statics/js/theme-editor.js`
  - 多语言字段标识
  - 语言切换器
  - `reloadWidgetConfigWithLocale()`
  - `saveWidgetConfigWithLocale()`
  - i18n 编辑按钮与面板
- 主题配置基础设施已落地：
  - `Helper/ThemeConfigManager.php`
  - `Helper/PreviewManager.php`
  - `Helper/ConfigLoader.php`
  - `Controller/Backend/Config/Layout.php`
- 主题编辑器已有部分自动化测试：
  - `test/Contract/ThemeEditorUpdateConfigContractTest.php`
  - `test/Contract/ThemeEditorPreviewContractTest.php`
  - `test/Integration/ThemeEditorPreviewFlowTest.php`
  - `test/Browser/theme-editor-preview.spec.js`

### 2. 当前文档问题

- `doc/开发/plan.md` 原内容仍是旧的消息通知子计划，和当前模块重点不匹配。
- `doc/widget-config-enhancement-plan.md` 仍保留原始未勾选阶段，但对应功能已在代码中实现。
- `doc/需求文档-完整版.md` 顶部状态与内部待办仍停留在开发期，未反映当前“主体完成、待收口”的状态。
- `doc/主题配置方案.md` 是设计稿，但 `ThemeConfigManager`、`PreviewManager`、`ConfigLoader` 已实现。
- `doc/widget-config-ui-beautification.md` 底部写“已完成”，但中部检查清单仍是原始待验收项。

## 计划范围

- 把主题编辑器、部件配置多语言、主题配置基础设施三条线统一到一个模块计划入口。
- 明确区分“已实现”“待补齐”“历史方案”。
- 保留历史文档作为设计和验收参考，不再让历史文档承担任务跟踪职责。

## 阶段一：实现现状固化（已完成）

| 项目 | 代码现状 | 结论 |
|------|----------|------|
| 部件参数多语言读写 | `ThemeData` 已提供完整 API | 已完成 |
| 主题编辑器多语言接口 | `ThemeEditor` 已提供 locale 读取/保存接口 | 已完成 |
| 配置面板多语言 UI | `theme-editor.js` 已实现切换、编辑和标识 | 已完成 |
| 主题配置加载链路 | `ThemeConfigManager` / `PreviewManager` / `ConfigLoader` 已实现 | 已完成 |
| 预览更新链路 | 预览与局部刷新相关接口和测试已存在 | 已完成 |
| 历史 `w_msg()` 子计划 | 属于已交付历史事项 | 已完成 |

## 阶段二：文档入口收口（本次完成）

| 任务 | 状态 | 说明 |
|------|------|------|
| 重写模块 `doc/开发/plan.md` | 已完成 | 以代码为准重建统一计划入口 |
| 重写模块 `doc/开发/task.md` | 已完成 | 把任务拆成“已确认完成 / 待补齐” |
| 给历史方案文档补状态说明 | 已完成 | 明确这些文档是历史参考，不再作为勾选入口 |

## 阶段三：待补齐项（进行中）

### 1. i18n 收口

- 盘点 `Weline/Theme/i18n/en_US.csv` 与 `Weline/Theme/i18n/zh_Hans_CN.csv` 中部件配置相关词条。
- 核对 `label`、`description`、`placeholder`、`options` 是否已和当前 `WidgetRegistry` / Meta 定义对齐。
- 清理旧文档中“缺词条”的泛化表述，改成可执行的缺口清单。

### 2. 自动化测试补齐

- 为 `getInstalledLocales()` 增加契约或集成测试。
- 为 `getWidgetConfig(locale)` 增加多语言读取覆盖。
- 为 `postSaveWidgetConfig(locale)` 增加多语言保存覆盖。
- 增加“可翻译字段 / 非可翻译字段”行为差异验证。
- 评估是否需要补一个浏览器测试覆盖语言切换器与多语言编辑面板。

### 3. 手工验收与文档回填

- 按 `doc/widget-config-i18n-testing.md` 重新跑一轮手工验证。
- 确认配置保存、语言切换、预览刷新、默认值回退是否与当前代码一致。
- 验证后再决定是否精简或归档 `需求文档-完整版.md` 中的历史待办段落。

## 完成标准

- `doc/开发/plan.md` 与 `doc/开发/task.md` 成为唯一任务跟踪入口。
- 历史方案文档顶部均明确“历史参考”身份。
- i18n 缺口有明确清单，而不是笼统待办。
- locale 相关自动化测试补齐，或至少明确未覆盖原因。
- 手工验证指南与当前路由、接口、交互行为一致。

## 历史已完成事项

- 后台 `w_msg()` 全局函数与 `Weline.Message` 模块已完成，不再纳入本收口计划主线。
- 该事项保留为模块历史交付记录，不再作为当前任务跟踪对象。
