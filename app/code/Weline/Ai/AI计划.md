# AI助手工具模块开发计划

> **2026-03 精简说明**：后台菜单已精简，仅保留「AI 管理」「场景配置」。以下计划中的营销工具、客户支持、训练数据、A/B 测试等独立页面已移除，核心能力（模型、适配器、供应商、助手）保留。

## 模块概述
**模块名称**: Weline_Ai  
**表前缀**: ai_  
**功能**: 统一的AI模型管理和助手工具平台
**支持特性**: 多租户、国际化、移动端、容器化、CI/CD

## 核心功能模块

### 1. 模型管理系统
#### 1.1 模型来源管理
- **模型收集**: 从模块统一目录自动收集AI模型
- **模型注册**: 系统启动时自动扫描并注册模型到数据库
- **模型更新**: 跟随系统更新自动收集新模型
- **模型版本管理**: 支持模型版本控制和回滚机制
- **模型部署管理**: 模型部署和发布流程管理
- **模型训练数据管理**: 训练数据收集、标注、版本控制
- **模型性能监控告警**: 实时监控、告警机制
- **模型A/B测试框架**: 模型效果对比测试
- **模型安全扫描**: 安全漏洞检测和修复
- **模型合规性检查**: 法规合规性验证

#### 1.2 模型复制与定制功能
- **原始模型**: 通过扫描自动收集的模型，这些模型的基本信息（供应商、模型代码、模型名称、版本）不可修改
- **原始模型保护**: 原始模型不能被删除，确保系统始终有可用的基础模型
- **模型复制**: 用户可以复制原始模型创建自定义配置的模型实例
- **复制模型特性**:
  - 复制时可以修改模型名称，以区分不同配置的实例
  - 复制的模型继承原始模型的基本信息（供应商、模型代码、版本）
  - 复制的模型可以有独立的API配置（API密钥、URL、参数等）
  - 复制的模型可以有独立的定价信息
  - 复制的模型可以有独立的代理配置
  - 复制的模型自动标记为非默认模型
  - 复制的模型可以被删除
- **模型标识**: 通过 `is_copied` 字段区分原始模型和复制模型
- **使用场景**:
  - 为不同项目配置不同的API密钥
  - 测试不同的模型参数组合（temperature、max_tokens等）
  - 为不同环境配置不同的代理设置
  - 实现模型配置的A/B测试

### 12. 多租户支持系统
#### 12.1 租户管理
- **租户隔离**: 数据、配置、权限完全隔离
- **租户配置**: 每个租户独立的配置管理
- **资源配额**: 租户级别的资源限制
- **计费管理**: 租户级别的计费和账单

#### 12.2 租户数据结构
```sql
ai_tenant 表结构:
- id: 主键
- tenant_name: 租户名称
- tenant_code: 租户代码
- tenant_type: 租户类型 (enterprise/individual/developer)
- status: 租户状态 (active/suspended/expired)
- plan_type: 订阅计划类型
- resource_quota: 资源配额配置
- billing_info: 计费信息
- created_time: 创建时间
- updated_time: 更新时间

ai_tenant_user 表结构:
- id: 主键
- tenant_id: 租户ID
- user_id: 用户ID
- role: 用户角色 (admin/member/viewer)
- permissions: 权限配置
- is_active: 是否激活
- created_time: 创建时间
- updated_time: 更新时间
```

### 13. 国际化支持系统
#### 13.1 多语言支持
- **界面国际化**: 支持多语言界面
- **内容国际化**: AI生成内容的多语言支持
- **时区支持**: 多时区时间处理
- **货币支持**: 多货币计费支持

#### 13.2 国际化数据结构
**依赖I18n模块现有表结构**:
```sql
-- 使用I18n模块的现有表
i18n_locale_name 表结构:
- locale_code: 语言代码 (主键)
- display_locale_code: 展示语言代码
- display_name: 语言显示名称

-- AI模块新增的国际化扩展表
ai_i18n_ai_content 表结构:
- id: 主键
- content_type: 内容类型 (prompt/response/error)
- content_key: 内容键
- locale_code: 语言代码
- content_value: 内容值
- context: 上下文
- created_time: 创建时间
- updated_time: 更新时间
```

### 14. 移动端支持系统
#### 14.1 移动端API
- **移动端专用API**: 针对移动端优化的API接口
- **推送通知**: 移动端推送通知
- **离线支持**: 离线数据同步
- **性能优化**: 移动端性能优化

#### 14.2 移动端数据结构
```sql
ai_mobile_device 表结构:
- id: 主键
- user_id: 用户ID
- device_id: 设备ID
- device_type: 设备类型 (ios/android)
- push_token: 推送令牌
- is_active: 是否激活
- last_active: 最后活跃时间
- created_time: 创建时间

ai_mobile_notification 表结构:
- id: 主键
- user_id: 用户ID
- device_id: 设备ID
- notification_type: 通知类型
- title: 通知标题
- content: 通知内容
- status: 发送状态
- sent_time: 发送时间
- created_time: 创建时间
```

### 15. 第三方集成系统
#### 15.1 集成管理
- **OAuth集成**: 支持第三方OAuth登录
- **API集成**: 与外部API的集成
- **Webhook支持**: 支持Webhook回调
- **数据同步**: 与外部系统的数据同步

#### 15.2 集成数据结构
```sql
ai_integration 表结构:
- id: 主键
- integration_name: 集成名称
- integration_type: 集成类型 (oauth/api/webhook)
- config: 集成配置
- status: 集成状态
- is_active: 是否激活
- created_time: 创建时间
- updated_time: 更新时间

ai_integration_log 表结构:
- id: 主键
- integration_id: 集成ID
- action: 操作类型
- request_data: 请求数据
- response_data: 响应数据
- status: 操作状态
- created_time: 创建时间
```

### 16. API文档系统
#### 16.1 文档管理
- **自动生成**: 自动生成API文档
- **交互式文档**: 支持在线测试API
- **版本管理**: API文档版本控制
- **多语言文档**: 支持多语言API文档

#### 16.2 文档数据结构
```sql
ai_api_documentation 表结构:
- id: 主键
- api_name: API名称
- api_version: API版本
- endpoint: 接口地址
- method: 请求方法
- description: 接口描述
- parameters: 参数说明
- response: 响应说明
- examples: 示例代码
- created_time: 创建时间
- updated_time: 更新时间
```

### 17. 开发者工具系统
#### 17.1 开发工具
- **SDK支持**: 多语言SDK
- **代码生成**: 自动生成客户端代码
- **测试工具**: API测试工具
- **调试工具**: 调试和诊断工具

#### 17.2 工具数据结构
```sql
ai_developer_tool 表结构:
- id: 主键
- tool_name: 工具名称
- tool_type: 工具类型 (sdk/generator/test/debug)
- language: 支持语言
- download_url: 下载地址
- version: 工具版本
- description: 工具描述
- is_active: 是否激活
- created_time: 创建时间
- updated_time: 更新时间
```

### 18. 计费系统
#### 18.1 计费管理
- **使用量计费**: 基于实际使用量计费
- **订阅计费**: 基于订阅计划计费
- **账单管理**: 自动生成账单
- **支付集成**: 支持多种支付方式

#### 18.2 计费数据结构
```sql
ai_billing_plan 表结构:
- id: 主键
- plan_name: 计划名称
- plan_type: 计划类型 (free/paid/enterprise)
- price: 价格
- currency: 货币
- billing_cycle: 计费周期
- features: 功能列表
- limits: 限制配置
- is_active: 是否激活
- created_time: 创建时间
- updated_time: 更新时间

ai_billing_invoice 表结构:
- id: 主键
- tenant_id: 租户ID
- invoice_number: 发票号
- amount: 金额
- currency: 货币
- status: 发票状态
- due_date: 到期日期
- paid_date: 支付日期
- created_time: 创建时间
- updated_time: 更新时间
```

### 19. 客户支持系统
#### 19.1 支持管理
- **工单系统**: 客户问题工单管理
- **知识库**: 常见问题知识库
- **在线客服**: 实时在线客服
- **反馈收集**: 用户反馈收集

#### 19.2 支持数据结构
```sql
ai_support_ticket 表结构:
- id: 主键
- tenant_id: 租户ID
- user_id: 用户ID
- ticket_number: 工单号
- subject: 工单主题
- description: 问题描述
- priority: 优先级
- status: 工单状态
- assigned_to: 分配给
- created_time: 创建时间
- updated_time: 更新时间

ai_support_knowledge 表结构:
- id: 主键
- category: 分类
- title: 标题
- content: 内容
- tags: 标签
- view_count: 查看次数
- is_active: 是否激活
- created_time: 创建时间
- updated_time: 更新时间
```

### 20. 营销工具系统
#### 20.1 营销管理
- **推广活动**: 营销推广活动管理
- **优惠券**: 优惠券系统
- **推荐系统**: 用户推荐系统
- **数据分析**: 营销数据分析

#### 20.2 营销数据结构
```sql
ai_marketing_campaign 表结构:
- id: 主键
- campaign_name: 活动名称
- campaign_type: 活动类型
- start_date: 开始日期
- end_date: 结束日期
- target_audience: 目标受众
- budget: 预算
- status: 活动状态
- created_time: 创建时间
- updated_time: 更新时间

ai_marketing_coupon 表结构:
- id: 主键
- coupon_code: 优惠券代码
- discount_type: 折扣类型
- discount_value: 折扣值
- usage_limit: 使用限制
- used_count: 已使用次数
- is_active: 是否激活
- created_time: 创建时间
- updated_time: 更新时间
```

#### 1.2 模型数据结构
```sql
ai_model 表结构:
- id: 主键
- supplier: 供应商名称 (如: OpenAI, Google, Anthropic等)
- name: 模型显示名称
- model_code: 模型代码标识
- version: 模型版本号
- config: JSON配置字段 (API配置、参数等)
- max_tokens: 最大token限制
- input_cost: 输入token成本
- output_cost: 输出token成本
- capabilities: JSON能力描述 (文本、图像、代码等)
- proxy_info: 代理信息 (可选，用于网络请求代理)
- tags: JSON标签配置 (支持多个标签)
- status: 模型状态 (active/inactive/deprecated)
- is_active: 是否激活
- created_time: 创建时间
- updated_time: 更新时间

-- 模型标签表
ai_model_tag 表结构:
- id: 主键
- name: 标签名称
- color: 标签颜色
- description: 标签描述
- sort_order: 排序
- is_active: 是否激活
- created_time: 创建时间

-- 模型标签关联表
ai_model_tag_relation 表结构:
- id: 主键
- model_id: 模型ID
- tag_id: 标签ID
- created_time: 创建时间

-- 模型版本管理表
ai_model_version 表结构:
- id: 主键
- model_id: 模型ID
- version: 版本号
- version_name: 版本名称
- version_description: 版本描述
- model_file: 模型文件路径
- model_size: 模型文件大小
- model_hash: 模型文件哈希值
- is_stable: 是否稳定版本
- is_current: 是否当前版本
- performance_score: 性能评分
- created_time: 创建时间
- updated_time: 更新时间

-- 模型测试记录表
ai_model_test 表结构:
- id: 主键
- model_id: 模型ID
- version_id: 版本ID
- test_name: 测试名称
- test_type: 测试类型 (accuracy/performance/security)
- test_data: 测试数据
- test_result: 测试结果
- test_score: 测试评分
- test_status: 测试状态 (pending/running/completed/failed)
- created_time: 创建时间
- completed_time: 完成时间

-- 模型部署记录表
ai_model_deployment 表结构:
- id: 主键
- model_id: 模型ID
- version_id: 版本ID
- deployment_name: 部署名称
- deployment_type: 部署类型 (production/staging/development)
- deployment_status: 部署状态 (pending/deploying/deployed/failed)
- deployment_config: 部署配置
- deployment_url: 部署地址
- created_time: 创建时间
- deployed_time: 部署时间

-- 模型基准测试表
ai_model_benchmark 表结构:
- id: 主键
- model_id: 模型ID
- benchmark_name: 基准测试名称
- benchmark_type: 基准测试类型
- benchmark_data: 基准测试数据
- benchmark_result: 基准测试结果
- benchmark_score: 基准测试评分
- benchmark_rank: 基准测试排名
- created_time: 创建时间
- updated_time: 更新时间

-- 模型训练数据表
ai_model_training_data 表结构:
- id: 主键
- model_id: 模型ID
- data_name: 数据名称
- data_type: 数据类型 (text/image/audio/video)
- data_source: 数据来源
- data_size: 数据大小
- data_hash: 数据哈希值
- data_version: 数据版本
- annotation_status: 标注状态 (pending/in_progress/completed)
- quality_score: 质量评分
- created_time: 创建时间
- updated_time: 更新时间

-- 模型性能监控表
ai_model_monitoring 表结构:
- id: 主键
- model_id: 模型ID
- metric_name: 指标名称
- metric_value: 指标值
- metric_threshold: 阈值
- alert_level: 告警级别 (info/warning/critical)
- alert_status: 告警状态 (active/resolved)
- alert_time: 告警时间
- resolved_time: 解决时间
- created_time: 创建时间

-- 模型A/B测试表
ai_model_ab_test 表结构:
- id: 主键
- test_name: 测试名称
- model_a_id: 模型A ID
- model_b_id: 模型B ID
- test_config: 测试配置
- test_data: 测试数据
- test_result: 测试结果
- winner_model: 获胜模型
- confidence_level: 置信度
- test_status: 测试状态 (pending/running/completed)
- created_time: 创建时间
- completed_time: 完成时间

-- 模型安全扫描表
ai_model_security_scan 表结构:
- id: 主键
- model_id: 模型ID
- scan_type: 扫描类型 (vulnerability/bias/toxicity)
- scan_result: 扫描结果
- risk_level: 风险级别 (low/medium/high/critical)
- vulnerability_count: 漏洞数量
- fix_suggestions: 修复建议
- scan_status: 扫描状态 (pending/running/completed)
- created_time: 创建时间
- completed_time: 完成时间
```

#### 1.3 模型管理界面
- **模型列表**: 显示所有可用模型，支持标签筛选
- **模型详情**: 查看模型详细信息、配置、价格等
- **模型状态**: 激活/停用模型
- **版本管理**: 模型版本控制、回滚、对比
- **测试管理**: 模型测试记录、结果分析
- **部署管理**: 模型部署、发布、监控
- **基准测试**: 模型性能基准测试和排名
- **训练数据管理**: 训练数据收集、标注、版本控制
- **性能监控**: 实时监控、告警管理
- **A/B测试**: 模型效果对比测试
- **安全扫描**: 安全漏洞检测和修复
- **合规检查**: 法规合规性验证
- **标签管理**: 创建、编辑、删除模型标签
- **标签筛选**: 根据标签快速筛选模型
- **保护机制**: 模型不可删除（系统收集）

### 2. 助手管理系统
#### 2.1 助手数据结构
```sql
ai_assistant 表结构:
- id: 主键
- name: 助手名称
- prompt: 提示词模板
- model_code: 关联的模型代码
- description: 助手描述
- model_config: JSON模型配置信息
- mcp_config: JSON MCP工具配置 (选择的MCP工具列表)
- user_proxy: 用户代理信息 (可选，优先使用)
- user_id: 创建用户ID (关联前端用户)
- is_active: 是否激活
- created_time: 创建时间
- updated_time: 更新时间
```

#### 2.2 助手管理功能
- **创建助手**: 选择模型、配置提示词、设置参数
- **模型切换**: 编辑时可切换模型
- **配置管理**: 模型配置、代理设置
- **MCP配置**: 为助手选择可用的MCP工具
- **助手列表**: 管理所有助手

### 3. API密钥管理系统
#### 3.1 密钥数据结构
```sql
ai_api_key 表结构:
- id: 主键
- name: 密钥名称
- user_id: 关联用户ID
- token: 密钥令牌
- status: 密钥状态 (pending/approved/rejected/frozen/deleted)
- quota_limit: 配额限制
- used_quota: 已使用配额
- is_active: 是否激活
- is_frozen: 是否冻结
- created_time: 创建时间
- updated_time: 更新时间
- last_used: 最后使用时间
- approved_time: 审核通过时间
- frozen_time: 冻结时间
- frozen_reason: 冻结原因
```

#### 3.2 密钥管理功能
- **密钥申请**: 用户提交密钥申请
- **审核流程**: 管理员审核密钥申请
- **密钥生成**: 自动生成唯一令牌
- **密钥管理**: 增删改查、冻结/解冻
- **配额控制**: 设置和使用配额限制
- **使用统计**: 记录使用情况和成本
- **权限控制**: 用户只能管理自己的密钥
- **安全监控**: 异常使用检测和告警

### 4. 前端用户接口
#### 4.1 前端控制器
- **工具介绍**: 展示AI助手工具功能
- **个人中心**: 用户登录后的管理界面
- **令牌管理**: 用户创建和管理自己的API密钥
- **MCP管理**: 用户管理自己的MCP服务器和工具
- **助手配置**: 用户配置助手的MCP工具选择
- **多媒体聊天**: 支持文本、图片、音频、视频等多媒体聊天
- **助手使用**: 用户使用助手功能

#### 4.2 用户权限
- **个人令牌**: 用户只能管理自己的API密钥
- **个人MCP**: 用户只能管理自己的MCP服务器配置
- **助手访问**: 基于令牌的助手访问控制
- **MCP工具选择**: 用户可为自己的助手选择MCP工具
- **使用限制**: 可配置使用频率和配额限制

### 5. API接口系统
#### 5.1 实时流式接口
- **流式响应**: 实时从AI模型获取数据并转发
- **代理支持**: 支持代理服务器请求
- **会话管理**: 相同session的后台请求免令牌
- **错误处理**: 完善的错误处理和重试机制

#### 5.2 接口特性
- **实时性**: 流式数据传输
- **安全性**: 基于令牌的访问控制
- **可扩展**: 支持多种AI模型
- **监控**: 请求日志和性能监控

#### 5.3 API版本管理
- **版本识别**: 使用Api目录和日期进行版本识别
- **版本切换**: 支持随时切换API版本
- **用户指定**: 用户可指定使用的API版本
- **语言本地化**: 支持指定语言locale
- **I18n依赖**: 依赖I18n模块的Local数据
- **向后兼容**: 保持向后兼容性
- **版本文档**: 每个版本的独立文档

#### 5.4 API版本数据结构
```sql
ai_api_version 表结构:
- id: 主键
- version_code: 版本代码 (如: 2024-01-15)
- version_name: 版本名称
- version_description: 版本描述
- api_directory: API目录路径
- supported_locales: 支持的语言列表 (JSON格式)
- default_locale: 默认语言
- is_active: 是否激活
- is_default: 是否默认版本
- release_date: 发布日期
- deprecation_date: 废弃日期
- changelog: 变更日志
- created_time: 创建时间
- updated_time: 更新时间

ai_api_version_usage 表结构:
- id: 主键
- user_id: 用户ID
- api_key_id: API密钥ID
- version_code: 使用的版本代码
- locale: 使用的语言
- usage_count: 使用次数
- last_used: 最后使用时间
- created_time: 创建时间
- updated_time: 更新时间

ai_api_version_locale 表结构:
- id: 主键
- version_code: 版本代码
- locale: 语言代码 (如: zh-CN, en-US)
- locale_name: 语言名称
- is_supported: 是否支持
- is_default: 是否默认语言
- created_time: 创建时间
- updated_time: 更新时间
```

#### 5.5 API版本目录结构
```
app/code/Weline/Ai/Controller/Api/
├── 2024-01-15/          # 2024年1月15日版本
│   ├── Chat.php         # 聊天接口
│   ├── Stream.php       # 流式接口
│   ├── Model.php        # 模型接口
│   └── Assistant.php    # 助手接口
├── 2024-02-01/          # 2024年2月1日版本
│   ├── Chat.php         # 聊天接口
│   ├── Stream.php       # 流式接口
│   ├── Model.php        # 模型接口
│   └── Assistant.php    # 助手接口
├── 2024-03-01/          # 2024年3月1日版本
│   ├── Chat.php         # 聊天接口
│   ├── Stream.php       # 流式接口
│   ├── Model.php        # 模型接口
│   └── Assistant.php    # 助手接口
├── Locale/              # 统一语言本地化（所有版本共享）
│   ├── zh-CN/           # 中文简体
│   │   ├── Chat.php
│   │   ├── Stream.php
│   │   ├── Model.php
│   │   └── Assistant.php
│   ├── en-US/           # 英文
│   │   ├── Chat.php
│   │   ├── Stream.php
│   │   ├── Model.php
│   │   └── Assistant.php
│   └── ja-JP/           # 日文
│       ├── Chat.php
│       ├── Stream.php
│       ├── Model.php
│       └── Assistant.php
└── latest/              # 最新版本（软链接）
    ├── Chat.php
    ├── Stream.php
    ├── Model.php
    └── Assistant.php
```

#### 5.6 API版本管理功能
- **版本创建**: 创建新的API版本
- **版本激活**: 激活指定版本
- **版本切换**: 切换API版本
- **版本废弃**: 废弃旧版本
- **版本比较**: 比较不同版本的差异
- **使用统计**: 统计各版本使用情况
- **语言管理**: 管理支持的语言列表
- **语言切换**: 支持语言切换
- **语言转换**: 自动语言转换
- **I18n集成**: 与I18n模块集成
- **向后兼容**: 保持向后兼容性

#### 5.7 API版本管理实现示例
```php
// API版本管理器
class ApiVersionManager {
    private $currentVersion;
    private $defaultVersion;
    
    public function __construct() {
        $this->defaultVersion = $this->getDefaultVersion();
        $this->currentVersion = $this->getCurrentVersion();
    }
    
    // 获取API版本和语言
    public function getApiVersionAndLocale($request) {
        // 1. 从请求头获取版本
        $version = $request->getHeader('X-API-Version');
        if ($version) {
            $version = $this->validateVersion($version);
        } else {
            // 2. 从URL参数获取版本
            $version = $request->getParam('version');
            if ($version) {
                $version = $this->validateVersion($version);
            } else {
                // 3. 从用户配置获取版本
                $version = $this->getUserPreferredVersion($request->getUserId());
                if ($version) {
                    $version = $this->validateVersion($version);
                } else {
                    // 4. 使用默认版本
                    $version = $this->defaultVersion;
                }
            }
        }
        
        // 获取语言设置
        $locale = $this->getApiLocale($request, $version);
        
        return [
            'version' => $version,
            'locale' => $locale
        ];
    }
    
    // 获取API语言设置
    private function getApiLocale($request, $version) {
        // 1. 从请求头获取语言
        $locale = $request->getHeader('X-API-Locale');
        if ($locale) {
            return $this->validateLocale($locale, $version);
        }
        
        // 2. 从URL参数获取语言
        $locale = $request->getParam('locale');
        if ($locale) {
            return $this->validateLocale($locale, $version);
        }
        
        // 3. 从用户配置获取语言
        $locale = $this->getUserPreferredLocale($request->getUserId());
        if ($locale) {
            return $this->validateLocale($locale, $version);
        }
        
        // 4. 如果没有指定语言，返回null（使用默认语言）
        return null;
    }
    
    // 验证语言有效性
    private function validateLocale($locale, $version) {
        $versionRecord = $this->getVersionRecord($version);
        if (!$versionRecord) {
            throw new ApiVersionException("版本 {$version} 不存在");
        }
        
        $supportedLocales = json_decode($versionRecord->getSupportedLocales(), true);
        if (!in_array($locale, $supportedLocales)) {
            // 如果请求的语言不支持，使用默认语言
            $locale = $versionRecord->getDefaultLocale();
        }
        
        return $locale;
    }
    
    // 从I18n模块获取默认语言
    private function getI18nDefaultLocale() {
        try {
            // 依赖I18n模块的Locale\Name模型
            $i18nLocale = new \Weline\I18n\Model\Locale\Name();
            $defaultLocale = $i18nLocale->getCollection()
                ->where('is_default', 1)
                ->where('is_active', 1)
                ->find()
                ->fetch();
            return $defaultLocale ? $defaultLocale->getLocaleCode() : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    // 获取版本默认语言
    private function getVersionDefaultLocale($version) {
        $versionRecord = $this->getVersionRecord($version);
        return $versionRecord ? $versionRecord->getDefaultLocale() : 'zh-CN';
    }
    
    // 语言转换处理
    public function processLanguageResponse($response, $locale) {
        if (!$locale) {
            // 如果没有指定语言，返回原始响应
            return $response;
        }
        
        // 如果指定了语言，进行语言转换
        return $this->translateResponse($response, $locale);
    }
    
    // 转换响应内容到指定语言
    private function translateResponse($response, $locale) {
        // 这里可以集成翻译服务，将响应内容转换为指定语言
        // 例如：使用AI模型进行语言转换
        $translatedResponse = $this->translateContent($response, $locale);
        
        return $translatedResponse;
    }
    
    // 翻译内容
    private function translateContent($content, $locale) {
        // 实现语言转换逻辑
        // 可以使用AI模型进行翻译，或者调用翻译API
        try {
            // 示例：使用AI服务进行翻译
            $translationService = new \Weline\Ai\Service\TranslationService();
            return $translationService->translate($content, $locale);
        } catch (\Exception $e) {
            // 如果翻译失败，返回原始内容
            return $content;
        }
    }
    
    // 验证版本有效性
    private function validateVersion($version) {
        $versionRecord = $this->getVersionRecord($version);
        if (!$versionRecord || !$versionRecord->isActive()) {
            throw new ApiVersionException("API版本 {$version} 不存在或已停用");
        }
        
        if ($versionRecord->isDeprecated()) {
            $this->logDeprecatedUsage($version);
        }
        
        return $version;
    }
    
    // 获取版本目录路径
    public function getVersionPath($version) {
        return "app/code/Weline/Ai/Controller/Api/{$version}/";
    }
    
    // 加载版本控制器
    public function loadVersionController($version, $controller, $locale = null) {
        $versionPath = $this->getVersionPath($version);
        
        // 如果有语言设置，优先加载语言版本
        if ($locale) {
            $localeControllerFile = $versionPath . "Locale/{$locale}/{$controller}.php";
            if (file_exists($localeControllerFile)) {
                require_once $localeControllerFile;
                $className = "\\Weline\\Ai\\Controller\\Api\\{$version}\\Locale\\{$locale}\\{$controller}";
                return new $className();
            }
        }
        
        // 加载默认版本控制器
        $controllerFile = $versionPath . $controller . '.php';
        if (!file_exists($controllerFile)) {
            throw new ApiVersionException("控制器 {$controller} 在版本 {$version} 中不存在");
        }
        
        require_once $controllerFile;
        $className = "\\Weline\\Ai\\Controller\\Api\\{$version}\\{$controller}";
        return new $className();
    }
    
    // 获取版本路径
    public function getVersionPath($version) {
        return "app/code/Weline/Ai/Controller/Api/{$version}/";
    }
    
    // 获取语言路径
    public function getLocalePath($version, $locale) {
        return "app/code/Weline/Ai/Controller/Api/{$version}/Locale/{$locale}/";
    }
    
    // 创建新版本
    public function createVersion($versionCode, $versionName, $description = '') {
        // 1. 创建版本目录
        $versionPath = $this->getVersionPath($versionCode);
        if (!is_dir($versionPath)) {
            mkdir($versionPath, 0755, true);
        }
        
        // 2. 复制基础文件
        $this->copyBaseFiles($versionPath);
        
        // 3. 创建版本记录
        $this->createVersionRecord($versionCode, $versionName, $description);
        
        // 4. 更新软链接
        $this->updateLatestLink($versionCode);
        
        return true;
    }
    
    // 切换版本
    public function switchVersion($versionCode) {
        $versionRecord = $this->getVersionRecord($versionCode);
        if (!$versionRecord) {
            throw new ApiVersionException("版本 {$versionCode} 不存在");
        }
        
        // 1. 停用当前版本
        $this->deactivateCurrentVersion();
        
        // 2. 激活新版本
        $this->activateVersion($versionCode);
        
        // 3. 更新软链接
        $this->updateLatestLink($versionCode);
        
        return true;
    }
    
    // 废弃版本
    public function deprecateVersion($versionCode, $deprecationDate) {
        $versionRecord = $this->getVersionRecord($versionCode);
        if (!$versionRecord) {
            throw new ApiVersionException("版本 {$versionCode} 不存在");
        }
        
        $versionRecord->setDeprecationDate($deprecationDate);
        $versionRecord->save();
        
        return true;
    }
}
```

#### 5.8 API版本使用示例
```php
// 客户端使用示例
// 1. 不指定语言（使用默认语言）
$headers = [
    'X-API-Version' => '2024-01-15',
    'Authorization' => 'Bearer your-api-key'
];
$url = 'https://api.example.com/v1/chat?version=2024-01-15';

// 2. 指定语言（返回对应语言的内容）
$headers = [
    'X-API-Version' => '2024-01-15',
    'X-API-Locale' => 'zh-CN',
    'Authorization' => 'Bearer your-api-key'
];
$url = 'https://api.example.com/v1/chat?version=2024-01-15&locale=zh-CN';

// 3. 多语言支持示例
$locales = [
    'zh-CN' => '中文简体',
    'en-US' => 'English',
    'ja-JP' => '日本語',
    'ko-KR' => '한국어',
    'fr-FR' => 'Français',
    'de-DE' => 'Deutsch',
    'es-ES' => 'Español',
    'ru-RU' => 'Русский'
];

// 4. 语言切换示例
$request = new ApiRequest();
$request->setHeader('X-API-Version', '2024-01-15');
$request->setHeader('X-API-Locale', 'en-US'); // 指定英文

$versionManager = new ApiVersionManager();
$result = $versionManager->getApiVersionAndLocale($request);
// 返回: ['version' => '2024-01-15', 'locale' => 'en-US']

// 5. 不指定语言示例
$request = new ApiRequest();
$request->setHeader('X-API-Version', '2024-01-15');
// 不设置 X-API-Locale

$result = $versionManager->getApiVersionAndLocale($request);
// 返回: ['version' => '2024-01-15', 'locale' => null]
```

#### 5.9 I18n模块集成示例
```php
// I18n模块集成
class I18nIntegration {
    private $i18nLocaleName;
    
    public function __construct() {
        $this->i18nLocaleName = new \Weline\I18n\Model\Locale\Name();
    }
    
    // 获取所有支持的语言
    public function getSupportedLocales() {
        $locales = $this->i18nLocaleName->getCollection()
            ->fetch();
        
        $supportedLocales = [];
        foreach ($locales as $locale) {
            $supportedLocales[] = [
                'locale_code' => $locale->getLocaleCode(),
                'display_locale_code' => $locale->getDisplayLocaleCode(),
                'display_name' => $locale->getDisplayName()
            ];
        }
        
        return $supportedLocales;
    }
    
    // 获取默认语言
    public function getDefaultLocale() {
        // 从I18n模块获取默认语言，如果没有则使用zh-CN
        try {
            $i18nModel = new \Weline\I18n\Model\I18n(
                new \Weline\I18n\Config\Reader(),
                new \Weline\I18n\Cache\I18NCache()
            );
            return $i18nModel->getLocalByCode('zh-CN');
        } catch (\Exception $e) {
            return 'zh-CN';
        }
    }
    
    // 验证语言是否支持
    public function isLocaleSupported($localeCode) {
        $locale = $this->i18nLocaleName->getCollection()
            ->where('locale_code', $localeCode)
            ->find()
            ->fetch();
        
        return $locale ? true : false;
    }
    
    // 获取语言信息
    public function getLocaleInfo($localeCode) {
        $locale = $this->i18nLocaleName->getCollection()
            ->where('locale_code', $localeCode)
            ->find()
            ->fetch();
        
        if (!$locale) {
            return null;
        }
        
        return [
            'locale_code' => $locale->getLocaleCode(),
            'display_locale_code' => $locale->getDisplayLocaleCode(),
            'display_name' => $locale->getDisplayName()
        ];
    }
    
}
```

#### 5.10 API控制器语言处理示例
```php
// API控制器示例
class ChatController {
    private $versionManager;
    private $aiService;
    
    public function __construct() {
        $this->versionManager = new ApiVersionManager();
        $this->aiService = new \Weline\Ai\Service\AiService();
    }
    
    public function handleChat($request) {
        try {
            // 获取版本和语言
            $versionAndLocale = $this->versionManager->getApiVersionAndLocale($request);
            $version = $versionAndLocale['version'];
            $locale = $versionAndLocale['locale'];
            
            // 处理聊天请求
            $prompt = $request->getParam('prompt');
            $response = $this->aiService->generateText($prompt);
            
            // 如果指定了语言，进行语言转换
            if ($locale) {
                $response = $this->versionManager->processLanguageResponse($response, $locale);
            }
            
            // 返回响应
            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'response' => $response,
                    'locale' => $locale,
                    'version' => $version
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    private function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        return json_encode($data);
    }
}
```

### 6. 会话管理系统
#### 6.1 会话管理功能
- **会话创建**: 用户登录后创建会话
- **会话维护**: 自动续期和状态管理
- **会话销毁**: 超时或主动销毁
- **会话统计**: 活跃用户和会话统计

#### 6.2 会话安全
- **令牌验证**: 会话令牌验证机制
- **超时控制**: 可配置的会话超时
- **并发限制**: 单用户并发会话限制

### 7. 成本管理系统
#### 7.1 费用计算
- **Token计费**: 基于实际使用量计费
- **模型定价**: 不同模型的差异化定价
- **费用预估**: 请求前费用预估
- **账单生成**: 定期生成使用账单

#### 7.2 预算控制
- **用户配额**: 个人使用配额限制
- **项目预算**: 项目级别的预算控制
- **预警机制**: 接近配额时的预警

### 8. 监控和运维系统
#### 8.1 性能监控
- **响应时间**: API响应时间监控
- **错误率**: 错误率统计和告警
- **使用量**: Token使用量统计
- **系统资源**: 服务器资源使用监控

#### 8.2 安全监控
- **异常访问**: 异常访问行为检测
- **频率限制**: 请求频率监控
- **内容过滤**: 敏感内容检测
- **审计日志**: 完整的操作审计日志

### 9. 商业洞察报表系统
#### 9.1 报表数据结构
```sql
ai_business_insights 表结构:
- id: 主键
- report_type: 报表类型 (daily/weekly/monthly/quarterly/yearly)
- report_date: 报表日期
- user_count: 用户数量
- active_users: 活跃用户数
- new_users: 新增用户数
- api_calls: API调用次数
- token_usage: Token使用量
- cost_total: 总成本
- revenue_total: 总收入
- profit_margin: 利润率
- model_usage_stats: JSON模型使用统计
- user_behavior_stats: JSON用户行为统计
- performance_metrics: JSON性能指标
- created_time: 创建时间
- updated_time: 更新时间
```

#### 9.2 多时间维度报表
- **今日统计**: 实时数据，包括当前小时的使用情况
- **昨日统计**: 昨日完整数据对比
- **近三天统计**: 最近三天的趋势分析
- **一周统计**: 过去7天的数据汇总
- **一月统计**: 过去30天的数据分析
- **一季度统计**: 过去90天的季度报告
- **一年统计**: 过去365天的年度分析

#### 9.3 报表内容
- **用户活跃度**: 日活、月活、用户增长趋势
- **模型使用情况**: 各模型使用频率、成本分析
- **收入分析**: 收入趋势、成本控制、利润率
- **性能指标**: 响应时间、错误率、成功率
- **业务洞察**: 用户行为分析、市场趋势预测
- **成本分析**: 详细成本分解、预算控制
- **ROI分析**: 投资回报率、效益评估

### 9. 服务模式系统
#### 9.1 双模式支持
- **接口模式**: 提供HTTP API接口供外部调用
- **PHP服务模式**: 提供静态方法供模块间调用

#### 9.2 接口模式功能
- **RESTful API**: 标准的REST接口
- **流式接口**: 实时流式响应
- **认证机制**: 基于令牌的访问控制
- **文档生成**: 自动生成API文档

#### 9.3 PHP服务模式功能
- **静态方法调用**: 通过静态方法提供服务
- **模型指定**: 可指定特定模型或使用默认模型
- **模块集成**: 其他模块可直接调用AI服务
- **配置管理**: 统一的配置管理机制

#### 9.4 服务调用示例
```php
// PHP服务模式调用示例
// 使用默认模型
$result = \Weline\Ai\Service\AiService::generateText($prompt);

// 指定特定模型
$result = \Weline\Ai\Service\AiService::generateText($prompt, 'gpt-4');

// 指定场景适配器
$result = \Weline\Ai\Service\AiService::generateText($prompt, null, 'translation');

// 指定模型和场景适配器
$result = \Weline\Ai\Service\AiService::generateText($prompt, 'gpt-4', 'translation');

// 流式调用
\Weline\Ai\Service\AiService::generateTextStream($prompt, function($chunk) {
    echo $chunk;
}, 'translation');
```

### 10. 场景适配器系统
#### 10.1 场景适配器概述
- **场景专用**: 为特定使用场景设计的AI生成适配器
- **自动扫描**: 从目录自动扫描并注册适配器
- **专业优化**: 针对特定场景优化AI生成效果
- **描述管理**: 提供适配器描述供运营指导

#### 10.2 场景适配器数据结构
```sql
ai_scenario_adapter 表结构:
- id: 主键
- adapter_code: 适配器代码 (如: translation, code_generation, content_creation)
- adapter_name: 适配器名称
- adapter_description: 适配器描述
- adapter_class: 适配器类名
- adapter_file: 适配器文件路径
- scenario_type: 场景类型 (translation/code/content/analysis/creative)
- capabilities: JSON能力描述
- input_format: 输入格式要求
- output_format: 输出格式规范
- is_active: 是否激活
- created_time: 创建时间
- updated_time: 更新时间
```

#### 10.3 场景适配器管理功能
- **自动扫描**: 系统启动时自动扫描适配器目录
- **自动注册**: 将扫描到的适配器注册到数据库
- **描述展示**: 显示适配器功能描述
- **状态管理**: 激活/停用适配器
- **保护机制**: 适配器不可删除（系统扫描）
- **只读展示**: 适配器信息只展示不可编辑

#### 10.4 场景适配器目录结构
```
app/code/Weline/Ai/Adapter/
├── TranslationAdapter.php      # 翻译场景适配器
├── CodeGenerationAdapter.php   # 代码生成适配器
├── ContentCreationAdapter.php  # 内容创作适配器
├── DataAnalysisAdapter.php     # 数据分析适配器
├── CreativeWritingAdapter.php  # 创意写作适配器
└── BaseAdapter.php             # 基础适配器类
```

#### 10.5 场景适配器接口规范
```php
// 基础适配器接口
interface ScenarioAdapterInterface {
    public function getAdapterCode(): string;
    public function getAdapterName(): string;
    public function getAdapterDescription(): string;
    public function getScenarioType(): string;
    public function getCapabilities(): array;
    public function getInputFormat(): array;
    public function getOutputFormat(): array;
    public function process(string $input, array $config = []): string;
    public function processStream(string $input, callable $callback, array $config = []): void;
}

// 翻译适配器示例
class TranslationAdapter implements ScenarioAdapterInterface {
    public function getAdapterCode(): string {
        return 'translation';
    }
    
    public function getAdapterName(): string {
        return 'AI翻译适配器';
    }
    
    public function getAdapterDescription(): string {
        return '专门用于AI翻译的适配器，支持多语言翻译，优化翻译质量和准确性';
    }
    
    public function getScenarioType(): string {
        return 'translation';
    }
    
    public function getCapabilities(): array {
        return [
            'multi_language' => true,
            'context_aware' => true,
            'quality_optimized' => true
        ];
    }
    
    public function process(string $input, array $config = []): string {
        // 翻译处理逻辑
        $targetLang = $config['target_language'] ?? 'zh-CN';
        $sourceLang = $config['source_language'] ?? 'auto';
        
        // 构建翻译专用提示词
        $prompt = $this->buildTranslationPrompt($input, $sourceLang, $targetLang);
        
        return $this->callAiModel($prompt, $config);
    }
    
    private function buildTranslationPrompt(string $text, string $sourceLang, string $targetLang): string {
        return "请将以下{$sourceLang}文本翻译成{$targetLang}，保持原文的语气和风格：\n\n{$text}";
    }
}
```

#### 10.6 场景适配器扫描器
```php
class ScenarioAdapterScanner {
    private $adapterPath = 'app/code/Weline/Ai/Adapter/';
    
    public function scanAdapters(): array {
        $adapters = [];
        $files = glob($this->adapterPath . '*.php');
        
        foreach ($files as $file) {
            $className = basename($file, '.php');
            $fullClassName = "\\Weline\\Ai\\Adapter\\{$className}";
            
            if (class_exists($fullClassName) && 
                in_array('ScenarioAdapterInterface', class_implements($fullClassName))) {
                
                $adapter = new $fullClassName();
                $adapters[] = [
                    'adapter_code' => $adapter->getAdapterCode(),
                    'adapter_name' => $adapter->getAdapterName(),
                    'adapter_description' => $adapter->getAdapterDescription(),
                    'adapter_class' => $fullClassName,
                    'adapter_file' => $file,
                    'scenario_type' => $adapter->getScenarioType(),
                    'capabilities' => json_encode($adapter->getCapabilities()),
                    'input_format' => json_encode($adapter->getInputFormat()),
                    'output_format' => json_encode($adapter->getOutputFormat())
                ];
            }
        }
        
        return $adapters;
    }
}
```

#### 10.7 服务调用中的场景适配器
```php
// 服务调用时使用场景适配器
public static function generateText($prompt, $modelCode = null, $scenarioCode = null) {
    $adapter = null;
    
    if ($scenarioCode) {
        $adapter = self::getScenarioAdapter($scenarioCode);
    }
    
    if ($adapter) {
        // 使用场景适配器处理
        return $adapter->process($prompt, [
            'model_code' => $modelCode ?: self::getDefaultModel('text')
        ]);
    } else {
        // 使用默认处理
        return self::defaultProcess($prompt, $modelCode);
    }
}

private static function getScenarioAdapter($scenarioCode) {
    $adapterRecord = $this->scenarioAdapter->reset()
        ->where('adapter_code', $scenarioCode)
        ->where('is_active', 1)
        ->find()
        ->fetch();
    
    if ($adapterRecord) {
        $className = $adapterRecord->getAdapterClass();
        return new $className();
    }
    
    return null;
}
```

### 11. 默认模型管理系统
#### 10.1 默认模型配置
```sql
ai_default_model 表结构:
- id: 主键
- model_code: 模型代码
- service_type: 服务类型 (text/image/audio/video/code/translation)
- is_default: 是否为该类型的默认模型
- priority: 优先级 (数字越小优先级越高)
- is_active: 是否激活
- created_time: 创建时间
- updated_time: 更新时间
```

#### 10.2 默认模型管理功能
- **模型分配**: 为不同服务类型分配默认模型
- **优先级设置**: 设置模型使用优先级
- **保护机制**: 默认模型不可删除
- **删除限制**: 删除时检查是否为默认模型
- **提示信息**: 删除失败时显示原因

#### 10.3 模型删除保护
- **删除检查**: 删除前检查是否为默认模型
- **保护提示**: 显示"该模型被设置为[服务类型]的默认模型，无法删除"
- **解除保护**: 需要先取消默认设置才能删除
- **批量保护**: 支持批量检查多个模型

#### 10.4 默认模型使用逻辑
```php
// 获取默认模型逻辑
public static function getDefaultModel($serviceType) {
    $defaultModel = $this->defaultModel->reset()
        ->where('service_type', $serviceType)
        ->where('is_default', 1)
        ->where('is_active', 1)
        ->order('priority', 'ASC')
        ->find()
        ->fetch();
    
    return $defaultModel ? $defaultModel->getModelCode() : null;
}

// 服务调用时自动选择模型
public static function generateText($prompt, $modelCode = null) {
    if (!$modelCode) {
        $modelCode = self::getDefaultModel('text');
    }
    // 执行AI生成逻辑
}
```

#### 10.5 管理界面功能
- **默认模型设置**: 为每种服务类型设置默认模型
- **模型优先级**: 调整模型使用优先级
- **保护状态显示**: 显示哪些模型被保护
- **批量操作**: 支持批量设置默认模型
- **删除确认**: 删除时显示保护状态和原因

## 技术架构

### 目录结构
```
app/code/Weline/Ai/
├── Controller/
│   ├── Backend/           # 后台管理控制器（2026-03 精简后）
│   │   ├── Index.php      # 重定向到 Manager
│   │   ├── Manager.php    # AI 管理聚合页
│   │   ├── Adapter.php    # 场景配置
│   │   ├── Model.php      # 模型管理
│   │   ├── Provider.php   # 供应商账户
│   │   ├── DefaultModel.php # 默认模型管理
│   │   └── Assistant.php  # 助手管理
│   ├── Frontend/          # 前端用户控制器
│   │   ├── Index.php      # 工具介绍
│   │   ├── Center.php     # 个人中心
│   │   └── Chat.php       # 聊天界面
│   └── Api/               # API接口控制器
│       ├── 2024-01-15/    # 2024年1月15日版本
│       │   ├── Chat.php   # 聊天接口
│       │   ├── Stream.php # 流式接口
│       │   ├── Model.php  # 模型接口
│       │   └── Assistant.php # 助手接口
│       ├── 2024-02-01/    # 2024年2月1日版本
│       │   ├── Chat.php   # 聊天接口
│       │   ├── Stream.php # 流式接口
│       │   ├── Model.php  # 模型接口
│       │   └── Assistant.php # 助手接口
│       ├── latest/        # 最新版本（软链接）
│       │   ├── Chat.php
│       │   ├── Stream.php
│       │   ├── Model.php
│       │   └── Assistant.php
│       └── VersionManager.php # 版本管理器
├── Model/                 # 数据模型
│   ├── AiModel.php        # AI模型
│   ├── AiAssistant.php    # AI助手
│   ├── AiApiKey.php       # API密钥
│   ├── AiDefaultModel.php # 默认模型
│   ├── AiSession.php      # 会话管理
│   └── AiUsage.php        # 使用统计
├── Service/               # 服务层
│   ├── AiService.php      # AI服务核心
│   ├── ModelService.php   # 模型服务
│   ├── AssistantService.php # 助手服务
│   └── MonitorService.php # 监控服务
├── Adapter/               # 场景适配器
│   ├── TranslationAdapter.php      # 翻译适配器
│   ├── CodeGenerationAdapter.php # 代码生成适配器
│   ├── ContentCreationAdapter.php # 内容创作适配器
│   ├── DataAnalysisAdapter.php    # 数据分析适配器
│   ├── CreativeWritingAdapter.php # 创意写作适配器
│   ├── BaseAdapter.php            # 基础适配器类
│   └── ScenarioAdapterInterface.php # 适配器接口
├── Helper/                # 辅助类
│   ├── ModelCollector.php # 模型收集器
│   ├── AdapterScanner.php # 适配器扫描器
│   ├── ProxyManager.php   # 代理管理
│   └── CostCalculator.php # 成本计算
├── Cache/                 # 缓存层
│   ├── RedisCache.php     # Redis缓存管理
│   ├── ModelCache.php     # 模型缓存
│   └── SessionCache.php   # 会话缓存
├── Queue/                 # 队列系统
│   ├── QueueManager.php   # 队列管理器
│   ├── ModelTestQueue.php # 模型测试队列
│   └── DeploymentQueue.php # 部署队列
├── Event/                 # 事件系统
│   ├── EventManager.php   # 事件管理器
│   ├── ModelEvent.php     # 模型事件
│   └── DeploymentEvent.php # 部署事件
├── Router/                # 路由管理
│   ├── ApiRouter.php      # API路由
│   ├── VersionRouter.php  # 版本路由
│   └── LoadBalancer.php   # 负载均衡
├── Config/                # 配置管理
│   ├── ConfigManager.php  # 配置管理
│   ├── ConfigCenter.php   # 配置中心
│   └── ConfigWatcher.php  # 配置监听
├── Logging/               # 日志追踪
│   ├── Logger.php         # 日志记录
│   ├── Tracer.php         # 操作追踪
│   └── Reporter.php       # 报告器
├── Tenant/                # 多租户
│   ├── TenantManager.php  # 租户管理
│   ├── TenantIsolation.php # 租户隔离
│   └── TenantConfig.php   # 租户配置
├── I18n/                  # 国际化
│   ├── LanguageManager.php # 语言管理
│   ├── TranslationManager.php # 翻译管理
│   └── LocaleManager.php  # 地区管理
├── Mobile/                # 移动端
│   ├── MobileApi.php      # 移动端API
│   ├── PushManager.php    # 推送管理
│   └── DeviceManager.php  # 设备管理
├── Integration/           # 第三方集成
│   ├── OAuthManager.php   # OAuth管理
│   ├── ApiIntegration.php # API集成
│   └── WebhookManager.php # Webhook管理
├── Documentation/         # API文档
│   ├── DocGenerator.php   # 文档生成器
│   ├── ApiExplorer.php    # API浏览器
│   └── DocVersionManager.php # 文档版本管理
├── Developer/             # 开发者工具
│   ├── SdkManager.php     # SDK管理
│   ├── CodeGenerator.php  # 代码生成器
│   └── TestTool.php       # 测试工具
├── Billing/               # 计费系统
│   ├── BillingManager.php # 计费管理
│   ├── InvoiceGenerator.php # 发票生成
│   └── PaymentManager.php # 支付管理
├── Support/               # 客户支持
│   ├── TicketManager.php  # 工单管理
│   ├── KnowledgeBase.php  # 知识库
│   └── FeedbackManager.php # 反馈管理
├── Marketing/             # 营销工具
│   ├── CampaignManager.php # 活动管理
│   ├── CouponManager.php  # 优惠券管理
│   └── AnalyticsManager.php # 营销分析
└── view/templates/        # 视图模板
    ├── Backend/           # 后台模板
    ├── Frontend/          # 前端模板
    └── Api/               # API响应模板
```

### 数据库设计
- **ai_model**: AI模型信息
- **ai_assistant**: AI助手配置
- **ai_api_key**: API密钥管理
- **ai_scenario_adapter**: 场景适配器配置
- **ai_default_model**: 默认模型配置
- **ai_session**: 会话管理
- **ai_usage_stats**: 使用统计
- **ai_business_insights**: 商业洞察报表
- **ai_user_permission**: 用户权限管理
- **ai_model_performance**: 模型性能监控
- **ai_cost_analysis**: 成本分析
- **ai_quota_usage**: 配额使用统计
- **ai_model_version**: 模型版本管理
- **ai_model_test**: 模型测试记录
- **ai_model_deployment**: 模型部署记录
- **ai_model_benchmark**: 模型基准测试
- **ai_model_training_data**: 模型训练数据
- **ai_model_monitoring**: 模型性能监控
- **ai_model_ab_test**: 模型A/B测试
- **ai_model_security_scan**: 模型安全扫描
- **ai_model_tag**: 模型标签
- **ai_model_tag_relation**: 模型标签关联
- **ai_tenant**: 多租户管理
- **ai_tenant_user**: 租户用户关联
- **ai_i18n_language**: 国际化语言
- **ai_i18n_translation**: 国际化翻译
- **ai_mobile_device**: 移动端设备
- **ai_mobile_notification**: 移动端通知
- **ai_integration**: 第三方集成
- **ai_integration_log**: 集成日志
- **ai_api_documentation**: API文档
- **ai_developer_tool**: 开发者工具
- **ai_billing_plan**: 计费计划
- **ai_billing_invoice**: 计费发票
- **ai_support_ticket**: 支持工单
- **ai_support_knowledge**: 支持知识库
- **ai_marketing_campaign**: 营销活动
- **ai_marketing_coupon**: 营销优惠券
- **ai_api_version**: API版本管理
- **ai_api_version_usage**: API版本使用统计
- **ai_i18n_ai_content**: AI内容国际化（新增）

### 技术特点
- **模块化设计**: 清晰的模块分离
- **服务化架构**: 统一的AI服务接口
- **双模式支持**: 接口模式和PHP服务模式
- **I18n依赖**: 依赖I18n模块的Local数据
- **多语言支持**: 支持多种语言本地化
- **场景适配器**: 针对特定场景的专业优化
- **自动扫描**: 自动发现和注册适配器
- **默认模型管理**: 智能的模型选择机制
- **保护机制**: 防止误删重要模型和适配器
- **商业洞察**: 多时间维度的数据分析报表
- **权限管理**: 细粒度的用户权限控制
- **安全防护**: 完善的安全防护机制
- **性能优化**: 缓存、队列、负载均衡
- **版本管理**: 模型版本控制和回滚机制
- **测试管理**: 模型测试和基准测试
- **部署管理**: 模型部署和发布流程
- **事件驱动**: 基于事件的异步处理
- **模块化架构**: 清晰的模块分离和依赖管理
- **统一API入口**: 统一的API路由和版本管理
- **配置管理**: 统一的配置管理
- **日志追踪**: 完整的操作日志和追踪
- **自动化运维**: 自动化部署、监控、告警
- **灾备恢复**: 数据备份和灾难恢复
- **性能调优**: 自动性能调优
- **容量规划**: 资源容量规划
- **故障自愈**: 自动故障检测和恢复
- **多租户支持**: 企业级多租户隔离
- **国际化支持**: 多语言界面和内容
- **移动端支持**: 移动端API和界面
- **第三方集成**: 与外部系统的集成
- **API文档系统**: 自动生成API文档
- **开发者工具**: SDK和开发工具包
- **计费系统**: 使用量计费和账单
- **客户支持**: 工单系统和客服
- **营销工具**: 推广和营销功能
- **API版本管理**: 基于日期的API版本控制
- **版本切换**: 支持随时切换API版本
- **向后兼容**: 保持API向后兼容性
- **扩展性**: 易于添加新的AI模型、服务和适配器

## 开发阶段

### 第一阶段：基础框架 (2-3周)
- 创建模块目录结构
- 实现基础数据模型
- 开发模型收集器
- 实现基本的CRUD操作
- 集成I18n模块依赖
- 实现多语言支持

### 第二阶段：核心服务 (3-4周)
- 实现AI服务核心类
- 开发双模式支持
- 实现场景适配器系统
- 开发适配器扫描器
- 实现默认模型管理
- 添加模型删除保护机制
- 开发后台管理界面
- 实现模型管理功能

### 第三阶段：API接口 (2-3周)
- 开发API接口
- 实现流式响应
- 实现API版本管理
- 开发版本切换功能
- 开发前端用户界面
- 实现聊天功能

### 第四阶段：监控运维 (1-2周)
- 实现使用统计
- 开发监控功能
- 添加成本计算
- 完善日志系统

## 优先级优化清单（已评估，高优先级放前）
> 说明：下面项为必须优先实现的改进，分为 P0（必须）、P1（优先）、P2（可选）。

- P0：版本与发布安全
  - **版本目录治理**：为每个API版本强制包含 `api_metadata.json`（包含：version_code、supported_locales、changelog、compatible_from、release_date）。
    - 作用：自动化校验、便于回滚与灰度。
  - **灰度与回滚机制**：实现 `VersionRouter` 支持按 `tenant/user/percent` 灰度路由，并保证快速回滚。

- P0：安全与合规
  - **密钥与敏感信息加密存储**：API Key、MCP token 与代理信息使用统一 `SecretStore`（支持 KMS/本地加密），并支持冻结/回收与审计日志。
  - **输入输出审计与内容安全**：中间件化检测链（敏感词、毒性、合规性），流式与非流式路径均生效。

- P0：翻译成本与稳定性
  - **翻译缓存与并发控制**：使用 Redis 缓存翻译片段，采用请求哈希去重并发控制，避免重复计费。
  - **翻译模式策略**：实现 `TranslationService` 的策略模式（light / high_fidelity），并可由租户/助手选择。

- P1：可靠性与队列
  - **队列任务与幂等**：异步任务（翻译、批量生成）使用队列，并设计幂等 key 与死信队列（DLQ）。
  - **重试策略**：指数退避 + 最大重试次数 + 失败告警。

- P1：监控与告警
  - **关键指标埋点**：按版本/locale/tenant 收集 QPS、延迟、错误率、翻译失败率、token 使用量。
  - **异常自动降级**：当错误率或成本超阈值时自动降级到低成本模型或拒绝新请求。

- P2：开发者体验与文档
  - **自动化文档**：为每个版本生成 OpenAPI 文档并能在线切换版本查看。
  - **示例 SDK**：提供 PHP/JS 示例，包含版本/locale 指定、流式消费示例。

## 实施建议（立刻可做的三个动作）
- 立即加入 `api_metadata.json` 校验并在 `VersionManager::createVersion` 中强制生成模板。（P0）
- 在 `TranslationService` 中加入缓存层（Redis）与策略模式接口（light/high_fidelity），并默认走缓存优先逻辑。（P0）
- 实现 `SecretStore` 接口草案并在 `AiApiKey` 模型中接入加密存储；后台密钥编辑界面支持冻结/解冻并记录审计。（P0-P1）

---

## 预期效果
## 预期效果

### 技术效果
- **统一AI服务**: 提供统一的AI模型管理平台
- **灵活调用**: 支持多种调用方式
- **场景优化**: 针对特定场景的专业适配器
- **智能选择**: 自动选择最适合的模型和适配器
- **安全可靠**: 完善的保护机制

### 业务效果
- **提升效率**: 简化AI模型的使用，场景适配器提供专业优化
- **降低成本**: 统一管理和优化资源
- **增强体验**: 提供更好的用户界面和场景化服务
- **专业服务**: 针对不同场景提供专业化的AI服务
- **扩展能力**: 支持更多AI应用场景和自定义适配器

