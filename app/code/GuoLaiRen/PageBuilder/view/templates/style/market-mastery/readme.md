# Market Mastery 模板

## 模板简介

Market Mastery 是一个专业的金融教育落地页模板，专注于技术分析和市场交易教育。模板采用深蓝色、黑色和白色的配色方案，营造专业、现代的金融风格。

## 模板特点

- **复用 Header 和 Banner**: Header 和 Banner 部分复用自 `jion-landing` 模板，保持一致性
- **多内容区域**: 包含 6 个主要内容区域，展示不同的教育内容
- **响应式设计**: 完全响应式，适配移动端、平板和桌面端
- **色系支持**: 支持多种色系配置，默认色系从设计图中提取

## 内容区域

### 1. Banner 区域
- 复用 `jion-landing` 的 Banner 样式
- 支持背景图片配置（桌面端和移动端）
- 支持标题、描述和按钮配置

### 2. Master Technical Market Analysis
- 黑色背景，白色文字
- 两列布局（左图右文字块）
- 展示两个文本块：Expert-Level Learning Paths 和 Market Intelligence Mastery

### 3. Advanced Analysis
- 白色背景，黑色文字
- 三列卡片布局
- 每个卡片包含图片、标题、描述和按钮
- 支持三个分析主题：Moving Average Magic、RSI Indicator Mastery、Visual Chart Analysis

### 4. Market Mastery
- 蓝色背景（#348FE2），白色文字
- 两列布局（左文字右图片）
- 展示市场掌握经验分享

### 5. Transform Your Investment Strategy
- 黑色背景，白色文字
- 居中布局
- 包含标题、描述和按钮

### 6. Unlock Technical Stock Support
- 白色背景，黑色文字
- 三列图标布局
- 每个图标包含圆形图标和文本

## 色系配置

### 默认色系（蓝色主题）

- **主背景色**: 浅蓝色 `#edf4ff`
- **强调色**: 亮蓝色 `#3B82F6`
- **图标色**: 浅蓝色 `#60A5FA`
- **文字颜色**: 深蓝色 `#163c85`（浅色背景）、白色（深色背景）
- **按钮颜色**: 蓝色背景 `#0065fb`，白色文字

色系配置文件位于 `colors/blue.phtml`，可以根据需要添加更多色系。

## 配置字段

模板支持通过可视化配置界面配置以下字段：

- **Header**: Logo、按钮文本、链接、内边距等
- **Banner**: 背景图片、标题、描述、按钮等
- **Master Technical Market Analysis**: 标题、描述、图片、文本块等
- **Advanced Analysis**: 标题、描述、按钮、三个卡片内容等
- **Market Mastery**: 标题、描述、图片等
- **Transform Strategy**: 标题、描述、按钮等
- **Unlock Support**: 标题、描述、三个图标文本等
- **Footer**: 版权文本、免责声明、内边距等

## 使用方法

1. 在 PageBuilder 后台创建新页面
2. 选择 `market-mastery` 作为样式模板
3. 在可视化配置界面中配置各个区域的内容
4. 选择适合的色系（默认或其他色系）
5. 保存并发布页面

## 技术细节

- 使用响应式 CSS clamp() 函数实现流畅的响应式设计
- 支持三端断点（Mobile: 375px, Tablet: 768px, Desktop: 1280px）
- 按钮事件追踪支持
- SEO 友好的 HTML 结构
- 图片懒加载优化

## 文件结构

```
market-mastery/
├── header.phtml          # 页面头部（复用 jion-landing 样式）
├── content.phtml         # 页面内容（Banner + 新内容区域）
├── footer.phtml          # 页面底部
├── colors/
│   └── blue.phtml        # 蓝色色系配置（默认）
└── readme.md             # 本文件
```

## 更新日志

### v1.0.0
- 初始版本
- 复用 jion-landing 的 header 和 banner
- 添加 5 个新的内容区域
- 支持默认色系配置

