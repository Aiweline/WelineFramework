# AutoLeadAgent 寻客模块任务清单

**最后更新**：2025-02-26

---

## 已完成 ✅

- [x] 模块基础结构与数据库
- [x] 后台管理界面（任务、配置、Token、Wasm、Mapping、目标网站、候选人）
- [x] 浏览器扩展（background-bridge、content、offscreen、inference-worker）
- [x] 搜索引擎爬取、深度爬取、分页、客户信息提取与质量评分
- [x] AgentConfig 模型扩展（hf_model_id、hf_model_enabled、hf_model_cache_size、网络配置）
- [x] Config 控制器：HuggingFace 搜索、模型详情、保存、网络配置
- [x] 配置页模型选择 UI、网络配置（镜像/代理）
- [x] hf-model-manager.js（Chrome AI 优先 + WebLLM 降级）
- [x] model-lifecycle.js、config-ui-renderer、config-models、config-download-manager
- [x] 模型下载进度弹窗
- [x] 配置持久化（布尔值解析修复）
- [x] 下载流程改用 MODEL_LOAD_REQUEST
- [x] 下载完成 autoLoad 时按钮恢复逻辑修复（originalButtonStates 遍历）
- [x] 模型选择内存限制（超出设备内存的模型禁用）
- [x] 国际化支持
- [x] 任务启动前 Chrome / 模型 / MCP 三项检查
- [x] 页面加载时非 Chrome 浏览器弹窗提示
- [x] 任务错误提示改用 BackendToast（移除 alert）

---

## 进行中 🔄

- [ ] 模型下载进度与 MODEL_LOAD_PROGRESS 消息格式对齐（扩展与前端）

---

## 待办事项

### 高优先级 [P1-P2]

- [x] 任务启动前浏览器检查：非 Chrome 弹窗提示并阻止
- [x] 任务启动前模型检查：未配置/未启用时提示
- [x] 任务启动前 MCP 扩展检查：未安装时提示
- [x] browser-detector.js：Chrome 检测、兼容性判断
- [x] Index 页加载时 Chrome 检测弹窗

### 中优先级 [P3]

- [ ] 统一翻译接口 translateIfNeeded（Google 优先、模型降级、缓存）
- [ ] ReAct Agent：react-agent.js think/act/observe/reactLoop
- [ ] MCP 客户端：mcp-client.js connect/listTools/callTool
- [ ] 扩展内 MCP 工具集：browser_navigate、browser_snapshot、browser_extract 等
- [ ] prompts.js：工具描述注入、Few-shot 示例、多语言
- [ ] 逐步移除规则推理，改为模型决策 + MCP 调用

### 低优先级 [P4-P5]

- [ ] WASM 扩展：mcp_protocol.cpp、agent_brain.cpp、wasm-bridge.js
- [ ] 单元测试与集成测试补全
- [ ] 更新 README、使用指南、部署指南、架构设计
- [ ] 支持 inotify 监控（Linux，若适用）

---

## 任务标记说明

- `[x]` 已完成
- `[ ]` 未开始
- `[/]` 进行中

### 优先级

- P1 紧急
- P2 高
- P3 普通
- P4 低
- P5 最低
