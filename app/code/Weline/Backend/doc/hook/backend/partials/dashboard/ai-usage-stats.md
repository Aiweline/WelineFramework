# Weline Backend - Hook: ai-usage-stats

## Hook 信息

- **Hook 名称**：`Weline_Backend::backend::partials::dashboard::ai-usage-stats`
- **显示名称**：AI 使用统计区块
- **定义模块**：Weline_Backend
- **区域**：backend
- **类型**：partials
- **组件**：dashboard
- **位置**：ai-usage-stats

## 功能说明

在后台统计/仪表盘等 Tab 面板中展示 AI 使用量统计（今日 tokens、花费等），可由 Weline_Ai 等模块实现。

## 实现方式

在实现模块的 `view/hooks/` 下按路径创建模板文件，路径与 hook 名对应（`::` 对应目录）：

- 路径：`view/hooks/Weline_Backend/backend/partials/dashboard/ai-usage-stats.phtml`
- 命名规范：component 与 position 仅使用小写字母和连字符（禁止下划线）

## 使用位置

- `app/code/Weline/Backend/view/templates/Backend/Statistics/index.phtml` 中通过 `getHook('Weline_Backend::backend::partials::dashboard::ai-usage-stats')` 调用。
