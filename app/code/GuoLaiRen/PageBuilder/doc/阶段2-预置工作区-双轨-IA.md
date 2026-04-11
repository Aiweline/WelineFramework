# 阶段 2 预置工作区 — 双轨 IA（信息架构）

> 对应总规划「阶段 2」：同一工作区内 **默认 HTML 区块轨** 与 **高级虚拟主题轨** 长期并行；**首版全站二选一**（`workspace_track`）。

## 用户心智

| 模式 | 用户画像 | 预置内容 | 切换后果 |
|------|----------|----------|----------|
| **默认（HTML 区块）** | 多数建站用户 | 每页类型一组 `blocks[]`（占位或 AI 产出） | 构建走 `runHtmlBlocksBuildOperation`，无虚拟主题 |
| **高级（虚拟主题）** | 要主题级控制 | VirtualTheme + 既有可视化 | 构建走原虚拟主题编排 |

## UI 落点（PageBuilder 工作区）

- 顶栏步骤条下增加 **「阶段2 · 预置工作区双轨」** 卡片：当前轨徽章、`site_ready` 状态、两个模式按钮、`开发：标记域名已就绪`（`merge_scope` 写 `site_ready=1`）。
- 「生成」按钮行为：由当前 `workspace_track` 决定 SSE 内部分支（见 `AiSiteAgent::runBuildOperation`）。

## Scope / handoff 契约

- `workspace_track`: `virtual_theme` | `html_blocks`
- `site_ready`: `0` | `1`（默认 `1` 兼容旧数据；与 Websites handoff 对齐）
- Websites `PageBuilderProvider` 在 `getWorkbenchConfig` 的 `scope` 中透传上述键。

## 与阶段 3 的边界

- 阶段 3 物化：`html_blocks` → `materializeHtml` + 发布快照消毒；`virtual_theme` → 原 `materialize` + 虚拟主题更新。

## 与实现对账（2026-04-10）

- 工作区实际 UI、嵌入预览与 SSE 前端逻辑以 `GuoLaiRen_PageBuilder` 下 [`view/templates/Backend/AiSiteAgent/workspace.phtml`](../view/templates/Backend/AiSiteAgent/workspace.phtml) 为准；服务端轨切换与构建以 [`Controller/Backend/AiSiteAgent.php`](../Controller/Backend/AiSiteAgent.php)（含 `runBuildOperation`）为准。
- 若本节「UI 落点」与线上界面有差异，以上述代码为准并回写本节。
