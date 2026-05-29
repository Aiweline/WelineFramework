# PageBuilder AI 建站 — 懒加载租借存储架构

## 目标

持久层保存完整 blueprint / 块 HTML；进程内只保留 **ScopeManifest**（小对象）。大对象通过 `AiSiteSessionRuntime` 租借 API 加载，闭包结束 **脱水归还**。

## 三层存储

| 层 | 键 | 说明 |
|---|---|---|
| L1 ScopeManifest | `scope_json` | `build_tasks`、`design_tokens`、`language_contract`、`virtual_page_index`、`theme_css_ref`、`_artifact_refs` |
| L2 ArtifactStore | DB + 文件 | `execution_blueprint`、`build_blueprint` 等 |
| L3 ComponentStore | `VirtualThemeComponent` | 块 `phtml` / `default_config` |

## 核心 API

- `AiSiteAgentSessionService::loadScopeManifest()` — 不 hydrate 大 artifact
- `AiSiteSessionRuntime::withArtifact()` — 用时解码，dirty 写回，闭包外脱水
- `AiSiteSessionRuntime::withBlock()` — DB 读写组件，manifest 只更新 index
- `AiSiteSessionRuntime::withRenderedPage()` — 渲染后 unset HTML
- `AiSiteSessionRuntime::patchManifest()` — 小字段 merge

## 原子任务索引

M-00 `AiSiteScopeManifestPolicy` · M-01~M-04 `AiSiteSessionRuntime` · M-05~M-08 构建链迁移 · M-09~M-12 工作台/CLI/回归
