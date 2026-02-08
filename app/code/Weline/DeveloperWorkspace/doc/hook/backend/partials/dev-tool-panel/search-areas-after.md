# Weline_DeveloperWorkspace::backend::partials::dev-tool-panel::search-areas-after

## 描述

在开发工具面板搜索区域之后注入扩展标签对应的搜索区 HTML 与注册脚本。扩展模块在此输出搜索区容器（id 约定为 `dev-tool-search-area-{tabId}`）并通过 `DevToolPanel.registerExtensionTab(tabId, loadContentFn)` 注册内容加载函数。

## Hook 信息

- **Hook 名称**：`Weline_DeveloperWorkspace::backend::partials::dev-tool-panel::search-areas-after`
- **定义模块**：Weline_DeveloperWorkspace
- **触发位置**：开发工具面板搜索区域之后

## 实现方式

在实现模块的 `view/hooks/` 下创建：

`view/hooks/Weline_DeveloperWorkspace/backend/partials/dev-tool-panel/search-areas-after.phtml`

输出：扩展标签的搜索区 div（id=`dev-tool-search-area-{tabId}`，默认 `style="display:none;"`），以及 `<script>` 中调用 `DevToolPanel.registerExtensionTab('tabId', function() { ... })`。是否加载、何时请求由扩展模块自行判断（如仅在本模块服务启用时才请求）。
