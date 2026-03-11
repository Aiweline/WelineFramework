# AutoLeadAgent 寻客模块开发计划

**状态**：🟡 进行中（status: in_progress）  
**当前阶段**：阶段三 - 模型与配置完善  
**完成度**：约 65%  
**最后更新**：2025-02-26

> 模块定位：基于零信任架构的智能寻客系统，结合本地端侧 AI 模型自动查找潜在客户。

---

## 一、里程碑与进度

| 阶段 | 名称 | 状态 | 完成度 |
|------|------|------|--------|
| 1 | 基础架构与数据层 | 🟢 已完成 | 100% |
| 2 | 浏览器扩展与爬取流程 | 🟢 已完成 | 100% |
| 3 | 模型配置与端侧推理 | 🟡 进行中 | 85% |
| 4 | ReAct Agent 与 MCP 集成 | 🔴 未开始 | 0% |
| 5 | 翻译与多语言 | 🟡 部分完成 | 40% |
| 6 | 测试与文档收尾 | 🔴 未开始 | 10% |

---

## 二、各阶段详情

### 阶段 1：基础架构与数据层 ✅

- 模块注册、数据库表（AgentToken、WasmHash、LeadCandidate、SearchTask、AgentConfig、SearchEngineMapping、TargetWebsite）
- Model / Service / Controller 分层
- 后台管理界面（任务、配置、Token、Wasm、Mapping、目标网站、候选人）
- 国际化（zh_Hans_CN、en_US）
- WASM 编译命令、哈希校验

### 阶段 2：浏览器扩展与爬取流程 ✅

- Chrome 扩展：background-bridge、content、offscreen、inference-worker
- 搜索引擎结果提取、深度爬取、分页、客户信息提取与质量评分
- 智能查询词生成、广告过滤（端侧模型）、心跳与 5 分钟无有效客户停止
- 来源类型收集事件、多模块扩展支持

### 阶段 3：模型配置与端侧推理 🟡

**已完成**：

- AgentConfig 扩展（hf_model_id、hf_model_enabled、hf_model_cache_size、网络配置镜像/代理）
- Config 控制器：HuggingFace 模型搜索、模型详情、保存配置、网络配置
- 配置页模型选择 UI、网络配置（镜像/代理）
- hf-model-manager.js：Chrome Built-in AI 优先、WebLLM 降级
- model-lifecycle.js、config-ui-renderer、config-models、config-download-manager
- 模型下载进度弹窗、配置持久化修复、内存限制模型选择

**待完善**：

- 模型下载改为 MODEL_LOAD 流程后，进度与状态闭环优化
- 扩展端 MODEL_LOAD_PROGRESS 广播与前端 UI 同步

### 阶段 4：ReAct Agent 与 MCP 集成 🔴

- mcp-client.js：连接、listTools、callTool
- 扩展内 MCP 工具集（browser_navigate、browser_snapshot、browser_extract 等）
- react-agent.js：think / act / observe / reactLoop
- prompts.js：工具描述注入、Few-shot、多语言
- 需求要求：完全模型驱动，移除规则推理

### 阶段 5：翻译与多语言 🟡

- 已有 translateWithGoogle() 等逻辑
- 待完成：统一 translateIfNeeded，Google 优先、模型降级、缓存与错误处理

### 阶段 6：测试与文档收尾 🔴

- 补充单元测试、集成测试
- 更新 README、使用指南、部署指南、架构设计

---

## 三、已知缺陷与待修复项

| 编号 | 描述 | 优先级 | 状态 |
|------|------|--------|------|
| D1 | 配置页布尔值解析（0/false 显示错误） | P1 | ✅ 已修复 |
| D2 | 下载进度弹窗未显示 | P1 | ✅ 已修复 |
| D3 | 下载流程 HF_DOWNLOAD_MODEL 扩展未支持 | P1 | ✅ 已改为 MODEL_LOAD |
| D4 | 下载完成 autoLoad 时报 disabled 未定义 | P1 | ✅ 已修复 |
| D5 | 模型选择无内存限制，大模型可选中导致失败 | P2 | ✅ 已加内存检测 |
| D6 | 扩展 HF_DOWNLOAD 与 MODEL_LOAD 进度消息格式不一致 | P2 | 待验证 |
| D7 | 规则推理与模型推理并存，需求要求完全模型驱动 | P3 | 待推进 |
| D8 | 任务启动前 Chrome / 模型 / MCP 三项检查 | P2 | ✅ 已实现 |

---

## 四、依赖与参考

- **需求文档**：`doc/需求文档.md`
- **架构设计**：`doc/架构设计.md`
- **README**：`README.md`
- **create-plan 技能**：`.cursor/skills/create-plan/SKILL.md`
