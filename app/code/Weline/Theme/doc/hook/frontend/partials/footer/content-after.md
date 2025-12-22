# Weline Theme 模块 - Hook 文档

## 概述

本文档详细说明了 Weline Theme 模块提供的 `Weline_Theme::frontend::partials::footer::content-after` Hook 及其使用方法。

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::partials::footer::content-after`
- **显示名称**：页脚内容之后
- **功能说明**：在渲染页脚主要内容之后触发，允许其他模块在页脚内容结束处注入内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Theme--frontend--partials--footer--content-after.phtml`
