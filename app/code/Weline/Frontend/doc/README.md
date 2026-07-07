# Weline Frontend 前端模块

## 当前有效定位

`Weline_Frontend` 负责前端页面入口、浏览器运行时接入、前端控制器能力和浏览器请求层对接，但**当前主题源规范与默认主题目录的权威位置在 `Weline_Theme`**。

如果你的任务是：

- 开发主题
- 改页面布局
- 覆盖默认主题
- 新增 widget / slot / partial / component

请先去读：

1. [`AI-INDEX.md`](./AI-INDEX.md)
2. [`../../Theme/doc/AI-INDEX.md`](../../Theme/doc/AI-INDEX.md)
3. [`../../Theme/doc/theme-inheritance-and-file-conventions.md`](../../Theme/doc/theme-inheritance-and-file-conventions.md)
4. [`../../Theme/doc/开发/Theme开发总指南.md`](../../Theme/doc/开发/Theme开发总指南.md)
5. [`../../Theme/view/theme/README.md`](../../Theme/view/theme/README.md)

## Frontend 模块真正该看的内容

### 浏览器业务请求

站内业务请求只能走：

- [`Weline.Api使用指南.md`](./Weline.Api使用指南.md)

核心规则：

- 必须使用 `Weline.Api.resource()` / `graph()` / `stream()` / `request()`
- 禁止 `fetch`
- 禁止 `XMLHttpRequest`
- 禁止 `$.ajax`
- 禁止 `axios`
- 禁止手写 query-bin URL

### 主题设计资料

`doc/主题设计/` 目录现在只保留为**补充型资料**，主要用于理解视觉 token、变量、颜色与历史设计结构。

如果其中内容与以下文档冲突，以后者为准：

- `app/code/Weline/Theme/doc/开发/Theme开发总指南.md`
- `app/code/Weline/Theme/doc/layout-discovery-guide.md`
- `app/code/Weline/Frontend/doc/Weline.Api使用指南.md`
- `dev/ai/global-constraints.md`

## 重要迁移说明

下面这些旧示例不要再当现行规范：

- 禁止复制 `$.ajax(...)`
- 禁止复制 `alert(...)`
- 直接 `/your-module/ajax`
- `design/frontend/default`
- `Weline_Frontend::theme/...` 作为默认主题主路径

这些历史示例之所以不能继续用，是因为当前框架已经明确收敛到：

- 主题目录：`Weline_Theme/view/theme`
- 设计覆盖：`app/design/**`
- 浏览器请求：`Weline.Api.*`
- 源模板优先，不改 `view/tpl` / `generated/`

## 推荐阅读顺序

1. AI 入口：[`AI-INDEX.md`](./AI-INDEX.md)
2. Theme AI 入口：[`../../Theme/doc/AI-INDEX.md`](../../Theme/doc/AI-INDEX.md)
3. Theme 总指南：[`../../Theme/doc/开发/Theme开发总指南.md`](../../Theme/doc/开发/Theme开发总指南.md)
4. 浏览器请求：[`Weline.Api使用指南.md`](./Weline.Api使用指南.md)
5. 主题资料索引：[`主题设计/README.md`](./主题设计/README.md)
6. Hook / Event / 具体前端模块文档：按任务命中相关文档

## 依赖关系

- `Weline_Framework`
- `Weline_Theme`

## 可继续使用的资料

- [`Weline.Api使用指南.md`](./Weline.Api使用指南.md)
- [`hook/frontend/head.md`](./hook/frontend/head.md)
- [`主题设计/`](./主题设计/)

## 文档维护原则

本 README 现在只保留当前有效入口，不再继续累积过时控制器、AJAX 或主题目录示例。若要补充前端开发文档，请优先更新：

- Theme 总指南
- Weline.Api 使用指南
- 具体模块自己的 `doc/`
