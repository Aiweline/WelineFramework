# Weline_DeveloperWorkspace::backend::partials::dev-tool-panel::tabs-after

## 描述

在开发工具面板标签栏「耗时统计」之后注入扩展标签按钮。由各模块自行实现（如 WLS 等），面板本身不包含任何具体业务。

## Hook 信息

- **Hook 名称**：`Weline_DeveloperWorkspace::backend::partials::dev-tool-panel::tabs-after`
- **定义模块**：Weline_DeveloperWorkspace
- **触发位置**：开发工具面板标签栏，在「耗时统计」标签之后

## 实现方式

在实现模块的 `view/hooks/` 下创建：

`view/hooks/Weline_DeveloperWorkspace/backend/partials/dev-tool-panel/tabs-after.phtml`

输出一个或多个 `<button class="dev-tool-tab" data-tab="扩展ID" onclick="DevToolPanel.switchMainTab(this, '扩展ID')">`，并在 search-areas-after 中提供对应搜索区与 `DevToolPanel.registerExtensionTab('扩展ID', loadContentFn)` 注册。
