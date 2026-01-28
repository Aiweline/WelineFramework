# AutoLeadAgent 智能寻客助手 - 浏览器扩展

AI 驱动的浏览器自动化扩展，帮助您自动发现潜在客户、提取数据、填写表单等。

## 功能特性

- 🤖 **AI 智能代理**: 使用自然语言描述任务，AI 自动执行
- 🔍 **自动寻客**: 在社交媒体、搜索引擎等平台自动寻找潜在客户
- 📊 **数据提取**: 智能提取网页结构化数据
- 📝 **表单自动填写**: 自动填写注册、登录等表单
- 🔄 **任务重放**: 记录并重放历史任务
- 🛡️ **防火墙保护**: 配置允许/禁止访问的网站

## 安装方法

### 方法一：开发者模式安装（推荐）

1. 打开 Chrome 浏览器，访问 `chrome://extensions/`
2. 开启右上角的 **开发者模式**
3. 点击 **加载已解压的扩展程序**
4. 选择 `browser-extension` 文件夹
5. 扩展安装完成！

### 方法二：打包安装

1. 在 `chrome://extensions/` 页面点击 **打包扩展程序**
2. 选择 `browser-extension` 文件夹作为扩展程序根目录
3. 点击打包，生成 `.crx` 文件
4. 将 `.crx` 文件拖拽到扩展页面安装

## 配置 API 密钥

首次使用需要配置 LLM API 密钥：

1. 点击扩展图标，或右键选择 **选项**
2. 在 **Models** 标签页添加 LLM 提供商
3. 支持的提供商：
   - OpenAI (GPT-4, GPT-4o 等)
   - Google (Gemini)
   - Anthropic (Claude)
   - 本地 Ollama
   - 任意 OpenAI 兼容 API

## 使用方法

### 侧边栏使用

1. 点击扩展图标打开侧边栏
2. 在输入框中描述您想要完成的任务
3. 点击发送，AI 将自动执行任务

### 示例任务

```
在 LinkedIn 上搜索 "AI Engineer" 并提取前10个结果的姓名和公司

去 Google 搜索 "best CRM software" 并提取前5个结果的标题和链接

打开 Twitter，搜索 #startup 相关帖子，提取发帖者信息
```

## 与后端集成

扩展支持与 AutoLeadAgent 后端系统通信：

1. 后端通过 `chrome.runtime.sendMessage` 发送任务
2. 扩展执行浏览器自动化任务
3. 结果通过消息回传给后端

### 通信示例

```javascript
// 后端发送任务给扩展
chrome.runtime.sendMessage(extensionId, {
    action: 'executeTask',
    task: '在 Google 搜索 "AI tools" 并提取结果'
}, response => {
    console.log('任务结果:', response);
});
```

## 技术架构

- **Puppeteer Core**: 使用 Chrome DevTools Protocol 控制浏览器
- **双代理架构**:
  - Navigator Agent: 执行具体的网页导航和交互
  - Planner Agent: 规划和优化任务执行策略
- **React + TailwindCSS**: 现代化的侧边栏和选项页 UI

## 目录结构

```
browser-extension/
├── manifest.json          # 扩展清单文件
├── background.iife.js     # 后台服务工作线程
├── buildDomTree.js        # DOM 树构建脚本
├── content/               # 内容脚本
├── side-panel/            # 侧边栏 UI
├── options/               # 选项页面 UI
├── permission/            # 权限请求页面
├── _locales/              # 多语言支持
│   ├── en/
│   ├── zh_CN/
│   └── ...
└── icons/                 # 图标资源
```

## 常见问题

### Q: 扩展无法控制某些网页？
A: Chrome 限制扩展访问 `chrome://` 页面和 Chrome Web Store。请确保您在普通网页上使用扩展。

### Q: API 调用失败？
A: 请检查 API 密钥是否正确配置，以及网络连接是否正常。

### Q: 任务执行太慢？
A: 可以在设置中调整页面加载等待时间和最大步骤数。

## 开发说明

本扩展基于 [Nanobrowser](https://github.com/nanobrowser/nanobrowser) 开源项目开发。

如需修改源码，请参考 `nanobrowser` 目录中的源代码，使用 `pnpm build` 重新构建。

## 许可证

Apache-2.0 License
