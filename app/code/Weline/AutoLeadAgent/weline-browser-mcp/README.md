# Weline Browser MCP

Weline 浏览器 MCP (Model Context Protocol) 扩展，支持本地离线推理和云端 API。

## 特性

- 🤖 **AI 自动化** - 使用自然语言控制浏览器，自动寻找潜在客户
- 🔒 **本地推理** - 支持本地 WASM 推理，数据不出本地，隐私有保障
- ☁️ **云端 API** - 支持 OpenAI、Gemini、Claude 等主流 LLM API
- 🌐 **多语言** - 支持中文、英文等多种语言
- 📱 **侧边栏** - 便捷的侧边栏交互界面

## 支持的本地模型

| 模型 | 大小 | 说明 |
|------|------|------|
| Qwen2.5 0.5B | 500MB | 轻量级中文模型 |
| Qwen2.5 1.5B | 1.5GB | 中等规模中文模型 |
| Llama 3.2 1B | 1.2GB | Meta 小型模型 |
| Phi-3 Mini | 2.3GB | 微软推理模型 |

## 目录结构

```
weline-browser-mcp/
├── chrome-extension/     # 扩展核心代码
│   ├── src/
│   │   ├── background/   # 后台服务
│   │   │   ├── agent/    # AI 代理逻辑
│   │   │   ├── browser/  # 浏览器控制
│   │   │   ├── llm/      # LLM 抽象层（本地+远程）
│   │   │   └── services/ # 服务模块
│   │   └── ...
│   └── public/           # 静态资源
├── pages/                # UI 页面
│   ├── content/          # 内容脚本
│   ├── options/          # 设置页面
│   └── side-panel/       # 侧边栏
├── packages/             # 共享模块
│   ├── storage/          # 存储管理
│   ├── i18n/             # 国际化
│   ├── ui/               # UI 组件
│   └── ...
└── dist/                 # 构建输出
```

## 开发

### 环境要求

- Node.js >= 22.12.0
- pnpm >= 9.15.1

### 安装依赖

```bash
pnpm install
```

### 开发模式

```bash
pnpm dev
```

### 构建

```bash
pnpm build
```

构建产物在 `dist/` 目录。

### 打包 ZIP

```bash
pnpm zip
```

## 架构说明

### LLM 层

支持两种推理模式：

1. **本地推理** (`ProviderTypeEnum.Local`)
   - 使用 transformers.js 在 WebWorker 中运行
   - 支持 WebGPU 加速（回退到 WASM）
   - 无需 API Key，完全离线

2. **远程 API** (OpenAI, Gemini, Claude 等)
   - 使用 LangChain 抽象层
   - 需要配置 API Key

### 代理架构

- **Navigator Agent** - 执行浏览器操作
- **Planner Agent** - 制定任务策略

## 许可证

MIT License - Weline
