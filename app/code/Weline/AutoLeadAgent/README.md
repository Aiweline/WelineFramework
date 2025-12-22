# Weline_AutoLeadAgent 自动寻客端侧模型模块

## 模块概述

自动寻客端侧模型模块是一个基于零信任架构的智能寻客系统。模块结合本地 Weline_Store 模块的店铺描述，自动在网上查找具有相同客户画像的潜在客户。

## 核心特性

1. **三层动态防御架构**
   - 安全外壳层：域名验证、Token验证、动态代码加载
   - WASM核心层：客户画像分析算法、客户评分算法、数据清洗规则
   - LLM引擎层：WebLLM (Phi-3 Mini)、ReAct Agent循环、浏览器工具集成

2. **安全机制**
   - 域名锁死：前端检查授权域名
   - 时效性Token：JWT包含过期时间和WASM哈希
   - 动态代码加载：核心JS通过API动态加载
   - WASM核心：关键算法编译为WASM
   - 内容哈希验证：SHA-256验证WASM完整性

3. **部署方式**
   - Chrome插件模式（优先）
   - Web兼容模式（回退）

## 模块结构

```
app/code/Weline/AutoLeadAgent/
├── register.php                          # 模块注册
├── etc/
│   ├── module.xml                        # 模块配置
│   └── backend/
│       └── menu.xml                      # 后台菜单
├── Setup/
│   └── Install.php                       # 安装脚本
├── Model/                                # 数据模型
│   ├── AgentToken.php                    # Token管理
│   ├── WasmHash.php                      # WASM哈希记录
│   ├── LeadCandidate.php                 # 潜在客户
│   └── SearchTask.php                    # 搜索任务
├── Service/                              # 业务服务
│   ├── TokenService.php                  # Token生成与验证
│   ├── WasmService.php                   # WASM管理
│   ├── StoreProfileService.php           # 店铺画像分析
│   └── LeadSearchService.php             # 寻客服务
├── Controller/                           # 控制器
│   ├── Api/
│   │   ├── Token.php                     # Token API
│   │   ├── Wasm.php                     # WASM API
│   │   ├── Search.php                    # 搜索 API
│   │   └── Core.php                      # 核心代码API
│   └── Backend/
│       └── Index.php                     # 后台管理
├── view/                                 # 视图资源
│   ├── statics/
│   │   ├── js/
│   │   │   ├── agent-shell.js            # 安全外壳
│   │   │   ├── agent-core.js             # 核心逻辑
│   │   │   ├── task-runner.js            # 任务执行器（与扩展通信）
│   │   │   └── backend-task-manager.js   # 后台任务管理
│   │   └── wasm/
│   │       └── agent-core.wasm           # WASM核心（需编译）
│   └── templates/
│       └── backend/
│           └── index.phtml               # 后台页面
├── browser-extension/                    # 浏览器扩展（Chrome/Edge/Firefox）
│   ├── manifest.json                     # 扩展配置（Manifest V3）
│   ├── background.js                     # 后台服务（爬取调度）
│   ├── content.js                        # 内容脚本（页面数据提取）
│   ├── popup.html                        # 扩展弹窗界面
│   ├── popup.js                          # 弹窗逻辑
│   ├── icons/                            # 扩展图标
│   └── README.md                         # 扩展安装说明
├── wasm/                                 # WASM相关
│   ├── src/                              # WASM源码
│   │   ├── agent_core.cpp                # 核心算法
│   │   └── agent_core.h
│   └── deps/                             # 编译依赖
└── i18n/                                 # 国际化
    ├── zh_Hans_CN.csv
    └── en_US.csv
```

## 安装步骤

1. **安装模块**
   ```bash
   php bin/w setup:upgrade
   ```

2. **编译WASM文件**
   ```bash
   cd app/code/Weline/AutoLeadAgent/wasm-src
   # 按照 wasm-src/README.md 中的说明编译WASM
   # 将生成的 agent-core.wasm 复制到 view/statics/wasm/ 目录
   ```

3. **注册WASM哈希**
   ```bash
   # 安装脚本会自动计算并注册WASM哈希
   # 或手动调用 WasmService::registerHash()
   ```

## API接口

### Token API

- `POST /api/v1/auto-lead-agent/token` - 生成Token
- `GET /api/v1/auto-lead-agent/token/validate` - 验证Token

### WASM API

- `GET /api/v1/auto-lead-agent/wasm/hash` - 获取WASM哈希
- `GET /api/v1/auto-lead-agent/wasm/download` - 下载WASM文件

### Search API

- `POST /api/v1/auto-lead-agent/search/create` - 创建搜索任务
- `GET /api/v1/auto-lead-agent/search/{taskId}` - 获取搜索结果

### Core API

- `GET /api/v1/auto-lead-agent/core` - 获取核心代码（动态加载）

## 使用说明

### 前端集成

1. 在页面中引入安全外壳：
   ```html
   <script src="/Weline/AutoLeadAgent/view/statics/js/agent-shell.js"></script>
   ```

2. 安全外壳会自动：
   - 检查域名授权
   - 验证Token
   - 动态加载核心代码
   - 加载并验证WASM模块

### 获取Token

```javascript
// 通过API获取Token
fetch('/api/v1/auto-lead-agent/token', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        domain: window.location.hostname,
        ttl: 3600
    })
})
.then(response => response.json())
.then(data => {
    // 保存Token到localStorage
    localStorage.setItem('auto_lead_agent_token', data.data.token);
});
```

## 开发状态

### 已完成

- ✅ 模块基础结构
- ✅ 数据库表结构（4个表）
- ✅ 数据模型（4个Model类）
- ✅ 服务层（4个Service类）
- ✅ API控制器（4个控制器）
- ✅ 后台管理界面（任务管理、实时日志、统计）
- ✅ 国际化支持
- ✅ WASM源码（C++）
- ✅ WASM编译命令（`php bin/w wasm:compile`）
- ✅ 前端安全外壳
- ✅ 前端核心逻辑加载器
- ✅ **浏览器扩展开发**（Chrome/Edge/Firefox）
  - 后台爬取服务（background.js）
  - 页面数据提取（content.js）
  - 扩展弹窗界面（popup.html）
  - 自动检测和安装提示
- ✅ **任务执行器**（task-runner.js）
  - 与浏览器扩展通信
  - 实时日志输出（推理日志、爬取日志）
  - 状态管理（待机、推理中、爬取中、已完成）
- ✅ **事件驱动架构**
  - 来源类型收集事件
  - 多模块扩展支持

### 待完成

- ⏳ WebLLM集成（Phi-3 Mini）
- ⏳ ReAct Agent实现
- ⏳ 客户画像智能分析
- ⏳ 单元测试和集成测试

## 浏览器扩展安装

浏览器扩展是实现真实爬取社交媒体的关键组件，可以绕过浏览器 CORS 限制。

### 支持的平台

| 平台 | 功能 | 数据提取 |
|------|------|----------|
| LinkedIn | 人物搜索 | 姓名、职位、地区、头像、主页 |
| Twitter/X | 用户搜索 | 用户名、昵称、简介、头像 |
| Facebook | 用户搜索 | 姓名、头像、主页 |
| Instagram | 用户搜索 | 用户名、头像、主页 |
| YouTube | 频道搜索 | 频道名、订阅数、简介 |

### 安装步骤

#### Chrome / Edge

1. 打开扩展管理页面：`chrome://extensions/` 或 `edge://extensions/`
2. 开启右上角的"开发者模式"
3. 点击"加载已解压的扩展程序"
4. 选择 `app/code/Weline/AutoLeadAgent/browser-extension` 文件夹
5. 刷新 AutoLeadAgent 后台页面

#### Firefox

1. 打开 `about:debugging#/runtime/this-firefox`
2. 点击"临时载入附加组件"
3. 选择 `browser-extension/manifest.json`
4. 刷新 AutoLeadAgent 后台页面

### 工作流程

1. **未安装扩展**：页面显示警告，使用模拟数据演示
2. **已安装扩展**：
   - 扩展通过 `postMessage` 与网页通信
   - 任务启动时调用扩展进行真实爬取
   - 扩展在后台打开标签页访问目标平台
   - 执行内容脚本提取用户数据
   - 返回真实客户数据

## 深度爬取和分页搜索流程

### 概述

新的搜索流程实现了对搜索引擎结果的深度爬取，通过递归访问每个搜索结果网站，提取客户信息，并支持自动分页继续搜索。

### 架构设计

```
搜索引擎搜索
  ↓
提取结果URL列表
  ↓
对每个URL：
  ├─ 访问URL
  ├─ 深度爬取（10层）
  │   ├─ 提取客户信息（邮箱/手机/社媒）
  │   ├─ 提取所有a标签
  │   └─ 递归访问下一层链接
  └─ 完成该URL，继续下一个
  ↓
当前页所有URL完成
  ↓
查找分页按钮（规则推测）
  ↓
点击下一页，重复流程
```

### 核心功能

#### 1. 搜索结果URL提取

**函数**: `extractSearchEngineResults()`

- **功能**: 从搜索引擎结果页面提取URL列表
- **返回格式**: `[{url: string, title: string, snippet: string}, ...]`
- **支持引擎**: Baidu, Google, Bing, DuckDuckGo, Yandex, Yahoo, 360搜索, 搜狗, Ecosia, Qwant, Startpage, Naver, Yahoo Japan, Ask.com, AOL Search
- **特点**: 
  - 只提取URL，不提取详细信息（详细信息在深度爬取时提取）
  - **使用端侧模型判断并跳过广告结果**
  - 自动过滤广告，只返回真实搜索结果

#### 2. 深度爬取

**函数**: `deepCrawlWebsite(url, maxDepth = 10)`

- **功能**: 递归访问网站，智能深度控制
- **流程**:
  1. 访问指定URL
  2. 识别页面类型（首页、产品页、博客、关于我们、联系页面等）
  3. 根据页面类型设置不同的最大深度（首页1-2层，产品页2-3层，博客3-5层，其他5-10层）
  4. 提取当前页面的客户信息（邮箱、手机、社媒、公司名称、联系人、职位、规模、行业、地理位置等）
  5. 智能链接过滤：优先访问重要性高的链接（Contact、About、Team等），过滤广告和无关链接
  6. 递归访问下一层链接（深度-1）
  7. 使用Set记录已访问的URL，避免重复访问
- **返回**: 所有找到的客户信息数组（包含质量评分）
- **限制**: 
  - 最大深度：根据页面类型智能调整（1-10层）
  - 每层最多访问20个链接（按重要性排序）
  - 每层超时：30秒

#### 3. 分页处理（每页处理完立即关闭标签页）

**函数**: `getNextPageUrl()` 和分页循环逻辑

- **功能**: 使用端侧模型识别分页按钮，提取下一页URL，创建新标签页访问
- **流程**:
  1. 当前页所有URL处理完成后，提取下一页URL（使用端侧模型分析分页按钮）
  2. 立即关闭当前页标签页
  3. 创建新标签页访问下一页URL
  4. 重复搜索和爬取流程
- **特点**:
  - 每页处理完立即关闭标签页，优化资源管理
  - 支持多语言分页按钮识别（Next, 下一页, Suivant, 次へ等）
  - 使用端侧模型智能评分分页按钮候选
  - 支持图标和箭头识别（>, », →, ›等）

#### 4. 客户信息提取增强

**函数**: `extractCustomerInfo()`

- **提取的信息类型**:
  - 基础信息：邮箱、电话、社媒账户
  - 公司信息：公司名称、公司规模（员工数）、行业标签、地理位置、成立时间
  - 联系人信息：联系人姓名、职位/角色
  - 页面信息：页面类型、网站域名

#### 5. 客户质量评分系统

**函数**: `calculateCustomerQualityScore(customer, profileInfo)`

- **评分维度**:
  1. 邮箱质量评分（企业邮箱 > 个人邮箱）
  2. 信息完整度评分（邮箱+电话+社媒 > 单一信息）
  3. 页面权威性评分（官网 > 第三方平台）
  4. 行业匹配度评分（基于店铺画像）
  5. 公司信息完整性（有公司名称、规模、地理位置等）
  6. 联系人信息（有联系人姓名和职位）
- **评分结果**:
  - 分数范围：0-10分
  - 等级：high (≥7分), medium (4-7分), low (<4分)
  - 包含评分原因列表

#### 6. 智能查询词生成

**函数**: `generateSearchQueries(keywords, profileInfo)`

- **生成策略**:
  1. 基础查询：直接使用关键词
  2. 关键词重排：不同顺序可能得到不同结果
  3. 行业词 + 地区
  4. 产品词 + 需求词
  5. 职位词 + 公司类型
  6. 痛点词 + 解决方案
  7. 长尾关键词组合（3-4个关键词）
  8. 地区查询
  9. 角色查询
  10. 特征查询
- **特点**: 自动去重，支持多语言查询词生成

#### 7. 智能深度控制和链接过滤

- **页面类型识别**: 自动识别首页、产品页、博客、关于我们、联系页面、团队页面等
- **深度控制**: 根据页面类型设置不同的最大深度
- **链接过滤**: 优先访问包含关键信息的链接（contact, about, team等），过滤广告和无关链接
- **重要性评分**: 使用端侧模型评估链接重要性，按重要性排序访问

- **功能**: 查找并点击分页按钮
- **识别方式**:
  - 文本匹配：'next', '下一页', '>', '»', 'next page', '下页', '后一页'
  - 选择器匹配：`.pagination a`, `#next`, `.next-page`
  - Class/ID匹配：包含'next'或'下一页'的元素
- **评分机制**: 根据匹配方式计算分数，选择分数最高的按钮
- **流程**:
  1. 提取页面中的分页按钮候选
  2. 使用规则推测哪个是"下一页"按钮
  3. 点击分页按钮
  4. 等待新页面加载
  5. 返回是否成功

#### 4. 内容脚本辅助函数

**`extractPageLinks()`**: 提取页面所有a标签链接
- 只提取同域名的链接
- 返回格式：`[{url: string, text: string, title: string}, ...]`

**`extractCustomerInfo()`**: 提取当前页面的客户信息
- 提取邮箱（正则匹配）
- 提取手机号（正则匹配）
- 提取社媒账户（LinkedIn, Twitter, Facebook等）
- 返回格式：`{emails: [], phones: [], socialMediaAccounts: {}, url: string}`

**`findPaginationButtons()`**: 查找分页按钮候选
- 查找所有可能的链接和按钮
- 根据文本、选择器、class、id进行匹配
- 返回按分数排序的候选列表

**`isAdvertisementResult()`**: 判断搜索结果是否是广告
- 使用端侧模型和规则判断
- 检查广告关键词、URL特征、class/id特征、广告标识
- 综合评分机制（分数 >= 0.5 认为是广告）
- 返回判断结果和原因

### 搜索流程详解

#### 步骤1: 搜索引擎搜索

1. 在指定搜索引擎中执行搜索查询
2. 创建搜索标签页（不关闭，用于分页）
3. 等待页面加载完成
4. 提取搜索结果URL列表
5. **使用端侧模型判断每个结果是否是广告**
   - 检查广告关键词（"广告"、"推广"、"Sponsored"等）
   - 检查URL特征（包含"/ad/", "/promo/"等）
   - 检查class/id特征（包含"ad", "sponsored"等）
   - 检查广告标识（"[广告]", "(Ad)"等）
   - 综合评分判断（分数 >= 0.5 认为是广告）
   - **跳过广告结果，只保留真实搜索结果**
   - 输出日志显示过滤的广告数量

#### 步骤2: 深度爬取每个URL

对每个搜索结果URL：

1. **访问URL**
   - 创建新标签页访问目标URL
   - 等待页面加载（30秒超时）
   - 检查页面是否可访问（404、403等）

2. **提取客户信息**
   - 执行 `extractCustomerInfo()` 脚本
   - 提取邮箱、电话、社媒账号
   - 保存到结果数组

3. **递归爬取**
   - 如果未达到最大深度（10层）：
     - 提取页面所有a标签链接
     - 限制每层最多访问20个链接
     - 递归访问下一层链接
     - 继续提取客户信息

4. **完成该URL**
   - 关闭标签页
   - 继续下一个URL

#### 步骤3: 分页处理

当前页所有URL爬取完成后：

1. **查找分页按钮**
   - 执行 `findPaginationButtons()` 脚本
   - 获取分页按钮候选列表
   - 选择分数最高的按钮

2. **点击分页按钮**
   - 根据元素信息查找按钮
   - 执行点击操作
   - 等待新页面加载

3. **继续搜索**
   - 如果成功分页：
     - 提取下一页的URL列表
     - 重复步骤2和步骤3
   - 如果无法分页或达到最大页数（10页）：
     - 停止该搜索引擎的搜索

### 错误处理

#### 超时控制
- 页面加载超时：30秒
- 每层深度超时：30秒
- 超时后记录日志并继续下一个URL

#### 已访问URL记录
- 使用 `Set` 数据结构记录已访问的URL
- 避免重复爬取相同URL
- 每个URL使用独立的visited set

#### 深度限制
- 最大深度：10层
- 达到最大深度后停止递归
- 记录日志提示

#### 无法访问URL处理
- 检测404、403等错误页面
- 检测页面加载超时
- 跳过无法访问的URL，继续处理下一个

#### 脚本执行错误
- 提取客户信息失败：记录日志，继续执行
- 提取链接失败：记录日志，继续执行
- 递归爬取失败：记录日志，继续下一个链接

### 性能优化

1. **并发控制**
   - 每个URL独立处理，不并发（避免资源耗尽）
   - 搜索引擎之间串行处理
   - 每层深度最多访问20个链接

2. **资源管理**
   - 及时关闭标签页，释放资源
   - 使用Set记录已访问URL，避免重复
   - 限制最大深度和最大页数

3. **日志记录**
   - 详细的日志记录每个步骤
   - 通过 `sendLogToFrontend` 实时输出到前端
   - 便于调试和监控

### 配置参数

- **最大深度**: 10层（可在 `deepCrawlWebsite` 函数中修改）
- **每层最大链接数**: 20个（可在深度爬取函数中修改）
- **最大页数**: 10页（可在 `handleCrawlRequest` 中修改）
- **页面加载超时**: 30秒（可在 `waitForTabLoad` 中修改）
- **心跳超时**: 5秒（可在 `background.js` 中修改 `HEARTBEAT_TIMEOUT`）
- **心跳发送间隔**: 3秒（可在 `task-runner.js` 的 `crawlWithExtension` 函数中修改）
- **无有效客户超时**: 5分钟（可在 `handleCrawlRequest` 中修改 `MAX_SEARCH_TIME`）

### 时间限制机制

系统实现了智能的时间限制机制，避免无效搜索浪费资源：

#### 1. 心跳超时机制（5秒）

- **触发条件**: 5秒内未收到来自外部（前端页面）的心跳信号
- **心跳发送**: 前端每3秒发送一次心跳信号到扩展
- **心跳处理**: 扩展收到心跳信号后更新对应任务的心跳时间
- **检查时机**: 
  - 每个查询开始前
  - 每个搜索引擎开始前
  - 每个URL爬取前
  - 完成当前页所有URL后
  - 分页前
- **行为**: 
  - 收到心跳信号时，更新心跳时间
  - 5秒内未收到心跳信号时，立即停止搜索
  - 输出日志说明停止原因（心跳超时）
  - 返回已找到的数据（如果有）
- **优势**: 
  - 确保扩展与前端保持连接
  - 如果前端页面关闭或失去响应，扩展自动停止
  - 避免资源浪费

#### 2. 5分钟无有效客户自动停止

- **触发条件**: 连续5分钟内未找到任何有效客户
- **有效客户定义**: 至少包含以下信息之一：
  - 邮箱地址
  - 手机号码
  - 社媒账户（至少一个平台）
- **检查时机**: 
  - 每个查询开始前
  - 每个搜索引擎开始前
  - 每个URL爬取前
  - 完成当前页所有URL后
  - 分页前
  - 完成当前搜索引擎后
  - 完成当前查询后
- **行为**: 
  - 找到有效客户时，重置计时器
  - 5分钟内未找到有效客户时，立即停止搜索
  - 输出日志说明停止原因
- **优势**: 
  - 避免在无效搜索上浪费时间
  - 提高搜索效率
  - 节省系统资源

### 使用示例

搜索流程由 `task-runner.js` 自动触发，无需手动调用。当用户在前端启动搜索任务时：

1. `task-runner.js` 调用浏览器扩展的 `crawl` 动作
2. `background.js` 的 `handleCrawlRequest` 函数处理请求
3. 执行上述搜索流程
4. 返回所有找到的客户信息

### 注意事项

1. **爬取频率**: 请遵守各平台使用条款，控制爬取频率
2. **资源消耗**: 深度爬取会消耗大量浏览器资源，建议限制并发数
3. **网络稳定性**: 确保网络连接稳定，避免频繁超时
4. **数据合规**: 爬取的数据仅用于商业合作目的，请遵守数据保护法规
5. **分页识别**: 分页按钮识别基于规则，可能无法识别所有网站的分页方式

## 注意事项

1. **安全性**：所有安全机制必须严格实现，不能有漏洞
2. **国际化**：所有用户可见文本必须使用 `__()` 函数
3. **WASM编译**：每次编译WASM后需要更新数据库中的哈希值
4. **Token管理**：Token应定期更新，建议TTL不超过24小时
5. **性能优化**：WASM和LLM加载需要考虑性能影响
6. **爬取限制**：请遵守各平台使用条款，控制爬取频率，避免账号被封禁
7. **数据合规**：爬取的数据仅用于商业合作目的，请遵守数据保护法规

## 依赖模块

- `Weline_Framework`: 核心框架
- `Weline_Store`: 店铺数据
- `Weline_I18n`: 国际化支持
- `Weline_Backend`: 后台管理

## 许可证

本模块由 Aiweline 开发，所有解释权归 Aiweline 所有。

