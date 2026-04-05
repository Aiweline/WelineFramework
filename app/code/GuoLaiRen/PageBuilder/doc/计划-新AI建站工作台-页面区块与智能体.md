# 新 AI 建站工作台 — PageBuilder 侧（页面区块、`ai_html`、物化）

> 模块：`GuoLaiRen_PageBuilder`  
> **文档拆分（避免混读）**：  
> - **WLS / 本机 hosts / `w_query` / 跨 OS / CI hosts / 与 Worker SSL 的通配衔接** → [`Weline/Server/doc/计划-AI建站-w_query与本机hosts.md`](../../../Weline/Server/doc/计划-AI建站-w_query与本机hosts.md)  
> - **工作台壳层、Agent Tools、本地供应商、域名订单与生命周期、`site_ready` 语义** → [`Weline/Websites/doc/计划-AI建站工作台-Websites侧.md`](../../../Weline/Websites/doc/计划-AI建站工作台-Websites侧.md)  
> 本文**只写 PageBuilder 范围内**的模型、接口、渲染与测试；跨模块约定以上述两份为准。

> 交叉参考：`../../../../../dev/ai/codex/plans/ai-site-agent.plan.md`、`../../../../../dev/ai/codex/AI工作台/Websites-AI建站工作台-总规划.plan.md`

## 目标与原则（PageBuilder）

- **职责**：`Controller/Backend`（含 `AiSiteAgent` 等）完成 **阶段 2→3** 的预置工作区、**整站/按类型生成**、**按块再生成**、**物化到 Page**、**发布**、SSE；与 Websites **handoff**；**默认产物为页面级 HTML 区块**（`blocks[]`），不以 `view/templates/style/*` 主题为第一路径。
- **动机**：虚拟主题 + Theme 链路过重；新默认路径直接生成内容；**有序 `blocks[]`** + 弱校验 `type`，**不对块内 HTML 做组件 schema 强约束**。
- **Websites / WLS**：不在此展开；见上方拆分链接。

## 已确认决策（仅 PageBuilder）

1. **旧路径共存**：新会话默认「页面区块 + `ai_html`」；旧会话与「高级/主题」仍走 VirtualTheme + style。
2. **区块模型**：`blocks[]` 有序；`block_id`、`type`（自由标签）、`html`；弱校验。
3. **HTML 安全**：编辑/预览宽；发布前**自动严格消毒**（`sanitize_auto`）。
4. **多语言**：**每 locale 一条 Page**，各页 `ai_layout`。
5. **Pixel**：块内类名/属性；**脚本在 `ai_html` 渲染管线统一注入**。
6. **双版本与发布历史**：编辑态 + 发布快照；**最近 N 份**（如 5）发布快照与回滚；N 与表结构实现时定。
7. **表单**：首版 **link_only**。
8. **三阶段中的 PageBuilder 段**：阶段 2 预置区（默认 HTML 片段轨 / 高级虚拟主题轨）→ 阶段 3 **页面生成器物化**；见下文。
9. **域名门禁（消费侧）**：`can_publish` 须组合 **内容已就绪 + 消毒 + 域名就绪（`site_ready` 等，定义见 Websites 计划）**；未就绪时仅草稿、禁止发布；**文案与 API 错误码**在 PB 控制器/模板实现。
10. **测试**：本模块 **PHPUnit 全绿**；**Playwright** 端到端含 PB 步骤时，**本机解析/CI hosts** 按 **Server** 计划实现，**域名数据** 按 **Websites** 计划实现。
11. **首版站点级二选一**：禁止同站混用部分 `ai_html` 与部分虚拟主题；**全站默认轨** 或 **全站高级轨** 二选一。

## 三阶段用户旅程（PageBuilder 视角）

整体流水线由 Websites 主持 **阶段 1**；PageBuilder 聚焦 **阶段 2 预置编辑** 与 **阶段 3 物化**（域名状态由 Websites 写入 scope，PB 只读展示与门禁）。

### 阶段 2 — 预置工作区（PB 产品重点）

| 模式 | 预置内容 | 编辑要点 |
|------|----------|----------|
| **默认** | 按页面类型的 **HTML 片段**、`blocks[]` | 对齐现有可视化交互；数据在 scope/工作区 |
| **高级** | **虚拟主题** | 显式切换；沿用 Virtual + Visual |

### 阶段 3 — 物化

- **虚拟主题轨**：物化后走 **theme 渲染**（`render_mode` 或等价）。
- **默认轨**：**Page 扩展字段** + **`ai_html` 拼接渲染**。
- **首版**：全站二选一轨（决策 11）。

### 域名门禁（展示与发布 API）

- 未就绪：提示草稿 only；就绪：`can_publish` 组合条件见上。

```mermaid
flowchart TB
  phase1[Phase1_prepareInfo_Websites]
  phase2[Phase2_workspaceEdit_PB]
  phase3[Phase3_materialize_PB]
  phase1 --> phase2
  subgraph phase2modes [Phase2_dualTrack]
    htmlBlocks[Default_htmlFragments]
    vtheme[Advanced_virtualTheme]
  end
  phase2 --> phase2modes
  phase2modes --> phase3
  domainState[Domain_state_from_Websites]
  domainState -.-> phase2
  domainState -.-> phase3
  phase3 --> gate{Domain_ready}
  gate -->|no| draftOnly[Draft_only]
  gate -->|yes| publish[Publish_after_sanitize]
```

## 核心架构变化（PageBuilder）

| 维度 | 现状 | 新默认 |
|------|------|--------|
| 内容载体 | 虚拟主题 + style | **Page + 扩展字段** |
| 首次生成 | 主题驱动 | **整站编排**；区块非 Theme |
| 可视化 | 组件 catalog | **blocks[]** 数据源 |
| 渲染 | theme 管线 | **双轨**：theme / `render_mode=ai_html` |

## 数据与生成策略

1. **整站首次生成**：各 locale 各页 `ai_layout.blocks[]`。
2. **持久化**：编辑态 + 最近 N 份发布快照。
3. **单块再生成**：按 `block_id` 替换；携带全站风格摘要。

## PageBuilder 实现要点

- `AiSiteAgent` 等：预置提交、生成、物化、发布、SSE。
- **物化**：VirtualTheme 轨 **或** Page 扩展 + `render_mode`。
- **Visual**：HTML 区块轨 vs 虚拟主题轨；避免无 schema HTML 强套 `ThemeComponent`。
- **`can_publish`**：与 Websites **域名字段**契约一致（见 Websites 计划）。

## 非功能（PB）

消毒、CSP、SEO 元字段：见本模块实现与已确认决策。

## 建议实施顺序（PageBuilder）

1. 依赖：**Server / Websites** 文档中的本地基建与 handoff 契约就绪（可并行）。
2. `Page` 扩展 schema（`render_mode`、`ai_layout`、发布快照 N）。
3. `ai_html` 渲染 + Pixel 注入。
4. 整站生成 + 单块再生成 + SSE。
5. workspace / Visual 接区块模型。
6. `can_publish` 与域名状态联调（对照 Websites）。
7. PHPUnit + 本文冒烟 + Playwright（跨模块环境见 Server/Websites）。

## 冒烟测试用例（PageBuilder 相关）

实现映射 PHPUnit、`http:request`、[`tests/e2e/`](../../../../../tests/e2e/)。**hosts/证书/供应商** 验收前提见 **Server / Websites** 计划。

### A. 三阶段与双轨（PB）

1. 阶段 2→3 准入与错误处理。  
2. 默认轨：`blocks[]`、单块再生成。  
3. 高级轨：虚拟主题关联。  
4. 物化默认轨：`render_mode`、编辑态与消毒后快照。  
5. 物化虚拟主题轨：与默认轨不串。

### B. 域名门禁（PB API/UI）

6. `site_ready=0`：发布失败、草稿成功、文案（**fixture 由 Websites 域提供**）。  
7. `site_ready=1`：发布成功。

### C. 渲染与安全

8. `ai_html` 顺序 + Pixel 注入。  
9. 发布快照消毒。

### D. 多语言与 handoff

10. 两 locale 两 Page。  
11. handoff 后 scope/域名状态一致（**契约见 Websites 计划**）。

### E. 回归

12. 现有 VirtualTheme 冒烟一条。

### F. Playwright（含 PB 步骤）

13. 真实浏览器完成含 **PageBuilder 工作区** 的建站链路；**hosts/解析** 见 Server 文档。  
14. **`*.weline.local`** 子域与证书链：**业务约定** Websites，**解析** Server。  
15. **CI**：PHPUnit + Playwright 绿；**流水线基建** Server/Websites 文档。

## 开放问题

- 虚拟主题 → 区块 HTML 导出（后置）。  
- 二期是否放开单站混用 render 模式。

## 实施任务勾选（仅 PB）

- [ ] 阶段 2 IA/线框与双轨切换  
- [ ] `can_publish` + 域名状态联调（契约对齐 Websites）  
- [ ] `Page` schema + 最近 N 份发布快照  
- [ ] `ai_html` + 消毒 + Pixel  
- [ ] 整站/单块生成 API/SSE  
- [ ] Visual/workspace 区块模型  
- [ ] 冒烟与 Playwright 中 PB 段断言  
- [ ] 本文与代码同步  

**不在此勾选**：`w_query`/hosts、默认供应商种子、通配证书实现细节 — 见 **Server / Websites** 文档任务列表。
