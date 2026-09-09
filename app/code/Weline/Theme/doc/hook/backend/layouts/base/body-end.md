# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::backend::layouts::base::body-end`
- **显示名称**：后台基础布局 Body 结束
- **功能说明**：在后台通用壳（default / dashboard / fullscreen 等含该 hook 的布局）`</body>` 前触发，供其他模块注入全局悬浮层、脚本或提示。

## 使用方法

实现路径：`view/hooks/Weline_Theme/backend/layouts/base/body-end.phtml`

## 使用场景

- 后台全局悬浮助手（如建站助手）
- 后台全局调试/提示浮层
- 后台全局脚本（慎用，优先页面级资源）

## 注意事项

- 登录 / minimal / print 等未引用此 hook 的布局不会执行
- 实现方须在 Owner（Theme）已声明规约后再挂 `view/hooks/` 文件
