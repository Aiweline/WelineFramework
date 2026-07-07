# Weline Frontend 主题设计文档

## 概述

本目录现在是**补充型主题设计资料目录**，用于保存颜色、变量、资产组织和历史设计思路。

如果你现在要做的是“当前框架的主题开发”，请先去读：

1. [`../../../Theme/doc/开发/Theme开发总指南.md`](../../../Theme/doc/开发/Theme开发总指南.md)
2. [`../../../Theme/doc/README.md`](../../../Theme/doc/README.md)
3. [`../../../Theme/view/theme/README.md`](../../../Theme/view/theme/README.md)

## 重要说明

本目录下不少文档仍包含历史路径或旧示例，例如：

- `Weline_Frontend::theme/...`
- `theme.json`
- 旧的 Frontend 主题目录设想

这些内容不能再当作当前实现规范的权威来源。若与上面三个文档冲突，以 Theme 模块文档为准。

## 这个目录现在还适合放什么

- 配色方案
- token 设计
- 组件视觉语言
- 资源命名与组织建议
- 历史设计资料

## 当前建议用法

### 如果你在做当前主题实现

请使用 `Weline_Theme/view/theme` 与 `app/design/**` 的现行体系，不要按本目录里的历史路径直接建新源码。

### 如果你在做视觉规范补充

本目录仍然适合记录：

- 配色系统
- 变量语义
- 主题切换视觉策略
- 组件设计语言

## 文档列表

### 核心资料

1. [配色系统设计规范](./配色.md)
2. [主题目录结构](./主题目录结构.md)
3. [theme/ 目录详细说明](./theme目录详细说明.md)
4. [变量与颜色主题区别说明](./变量与颜色主题区别说明.md)

### 目录资料

5. [variables/ 目录文档](./variables目录文档.md)
6. [colors/ 目录文档](./colors目录文档.md)
7. [components/ 目录文档](./components目录文档.md)
8. [layouts/ 目录文档](./layouts目录文档.md)
9. [partials/ 目录文档](./partials目录文档.md)
10. [assets/ 目录文档](./assets目录文档.md)
11. [config/ 目录文档](./config目录文档.md)

## 使用提醒

如果你看到本目录里出现下面这些历史示例，请不要直接照抄：

- `Weline_Frontend::theme/components/...`
- `Weline_Frontend::theme/layouts/...`
- `theme.json` 驱动当前主题运行时
- 旧主题目录作为现行默认目录

当前实现请改读：

- `app/code/Weline/Theme/doc/开发/Theme开发总指南.md`
- `app/code/Weline/Theme/doc/layout-discovery-guide.md`
- `app/code/Weline/Frontend/doc/Weline.Api使用指南.md`

## 贡献指南

1. 若是当前主题实现规则变更，优先更新 `Weline_Theme/doc/`，不要只改本目录。
2. 若是视觉 token / 配色资料补充，再更新本目录。
3. 新增变量时，更新相关颜色或变量文档。
4. 重大变更时，明确标注“历史设计资料”与“当前实现规范”的边界。
