# ElFinderFileManager 模块：媒体选择与分辨率配置

本模块子计划，总计划见 [.cursor/plans/媒体选择与分辨率配置_676aad06.plan.md](../../../../.cursor/plans/媒体选择与分辨率配置_676aad06.plan.md)。

## 目标

- manager URL 已通过 getBackendUrl 传递全部 params，recommend_* 等会进入 query。
- 选择器页面可选展示「建议尺寸：W×H」文案。

## 已完成

- [x] elfinder.html：从 URL 解析 recommend_width、recommend_height；存在时在 #elfinder-recommend-hint 显示「建议尺寸：W × H」。

## 涉及文件

- `app/code/Weline/ElFinderFileManager/view/templates/Backend/Connector/elfinder.html`
