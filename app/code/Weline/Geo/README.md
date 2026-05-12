# Weline_Geo 模块

生成式搜索引擎优化模块，专门向AI生成式搜索引擎（Google SGE、Perplexity、Bing Chat、OpenAI、Claude等）提供Feed，支持多平台、密钥管理、一键推送和自动更新推送功能。

## 功能特性

### 1. 平台管理
- 支持多个AI搜索引擎平台
- 平台配置管理（API端点、Feed格式等）
- 多账户支持（每个平台可配置多个账户）
- 账户密钥加密存储
- 连接测试功能

### 2. Feed管理
- 创建不同类型的Feed（内容、产品、文章等）
- 配置数据源（数据库、API、自定义）
- 支持多种Feed格式（JSON Feed、XML、RSS）
- Feed预览和生成
- 自动更新配置

### 3. 推送功能
- 一键推送Feed到指定平台
- 批量推送（同时推送到多个平台）
- 自动推送（内容更新时自动推送）
- 定时推送（通过Cron定时推送）
- 推送历史记录

### 4. 支持的平台
- Google SGE (Search Generative Experience)
- Perplexity
- Bing Chat
- OpenAI
- Claude (Anthropic)

## 安装

1. 确保模块已注册到系统中
2. 运行安装命令：
```bash
php bin/m module:upgrade Weline_Geo
```

## 使用说明

### 1. 配置平台

1. 进入后台：生成式搜索引擎优化 > 平台管理
2. 添加平台，配置平台代码、名称、API端点等
3. 为平台添加账户，配置API密钥（密钥会自动加密存储）
4. 测试连接，确保账户可用

### 2. 创建Feed

1. 进入：生成式搜索引擎优化 > Feed管理
2. 添加Feed，配置：
   - Feed名称和类型
   - 数据源类型和配置
   - 更新频率
   - 是否自动推送

### 3. 推送Feed

#### 手动推送
1. 进入：生成式搜索引擎优化 > 推送管理 > 一键推送
2. 选择Feed和平台
3. 点击"开始推送"

#### 自动推送
- 配置Feed时启用"自动推送"
- 内容更新时会自动触发推送（需要配置Observer事件）

#### 定时推送
- 通过Cron任务定时推送
- 使用命令行工具：`php bin/m geo:feed:push <feed_id>`

## 命令行工具

### 生成Feed
```bash
php bin/m geo:feed:generate <feed_id> [format]
```
示例：
```bash
php bin/m geo:feed:generate 1 json_feed
```

### 推送Feed
```bash
php bin/m geo:feed:push <feed_id> [platform_id]
```
示例：
```bash
# 推送到指定平台
php bin/m geo:feed:push 1 1

# 推送到所有平台
php bin/m geo:feed:push 1
```

## 数据库表

- `geo_platform`: 平台配置表
- `geo_platform_account`: 平台账户表
- `geo_feed`: Feed配置表
- `geo_feed_item`: Feed条目表
- `geo_push_log`: 推送日志表

## 安全说明

- API密钥使用AES-256-CBC加密存储
- 密钥仅在需要时解密
- 建议在生产环境中配置 `GEO_SECRET_KEY` 环境变量

## 开发说明

### 添加新平台适配器

1. 在 `Adapter/` 目录下创建新的适配器类
2. 继承 `BaseAdapter` 类
3. 实现必要的方法：
   - `generateFeed()`: 生成Feed
   - `pushFeed()`: 推送Feed
   - `testConnection()`: 测试连接
4. 在 `PlatformAdapterService` 中注册新适配器

### 事件监听

模块通过 `ContentUpdateObserver` 监听内容更新事件，自动创建Feed条目并触发推送。

## 依赖模块

- `Weline_Framework`: 核心框架
- `Weline_Backend`: 后台管理
- `Weline_I18n`: 国际化支持

## 版本

当前版本：1.0.0

## 作者

秋枫雁飞  
邮箱：aiweline@qq.com  
网址：aiweline.com  
论坛：https://bbs.aiweline.com

