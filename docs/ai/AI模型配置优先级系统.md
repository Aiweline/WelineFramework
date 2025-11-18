# AI模型配置优先级系统

## 概述

本系统实现了多层级AI模型配置优先级管理，支持前端用户和后端管理员的不同配置需求。

## 配置优先级

### 1. 前端用户调用优先级

1. **用户提供的配置** (最高优先级)
   - 用户在调用时直接提供的API密钥和配置
   - 适用于临时测试或特殊需求

2. **用户为模型配置的供应商账户**
   - 用户为特定模型配置的API密钥
   - 存储在用户账户中，仅该用户可见

3. **模型关联的配置**
   - 后台为模型配置的默认API密钥
   - 所有用户共享

4. **后台默认供应商账户** (最低优先级)
   - 系统默认的供应商账户配置
   - 作为最后的备选方案

### 2. 后端管理员调用优先级

1. **用户提供的配置** (最高优先级)
   - 管理员在调用时直接提供的配置

2. **模型关联的配置**
   - 后台为模型配置的API密钥

3. **后台默认供应商账户** (最低优先级)
   - 系统默认的供应商账户配置

## 实现架构

### 核心服务

#### ConfigResolver
- **位置**: `app/code/Weline/Ai/Service/ConfigResolver.php`
- **功能**: 解析多层级配置优先级
- **方法**:
  - `resolveConfig()`: 主配置解析方法
  - `resolveFrontendConfig()`: 前端配置解析
  - `resolveBackendConfig()`: 后端配置解析

#### AiService
- **位置**: `app/code/Weline/Ai/Service/AiService.php`
- **功能**: AI服务核心类，集成配置解析器
- **更新**: 新增 `userId` 和 `isBackend` 参数

### 数据模型

#### AiModel
- **位置**: `app/code/Weline/Ai/Model/AiModel.php`
- **新增方法**:
  - `getProviderConfig()`: 获取模型关联的配置
  - `setProviderConfig()`: 设置模型关联的配置
  - `getProviderConfigJson()`: 获取JSON格式的配置

#### Account
- **位置**: `app/code/Weline/Ai/Model/Provider/Account.php`
- **功能**: 供应商账户管理
- **字段**: `user_id`, `model_id`, `is_default`, `status`

### API控制器

#### 前端API
- **位置**: `app/code/Weline/Ai/Controller/Frontend/AiController.php`
- **功能**: 处理前端用户的AI调用
- **特点**: 需要用户认证，支持余额检查

#### 后端API
- **位置**: `app/code/Weline/Ai/Controller/Backend/ApiController.php`
- **功能**: 处理后端管理员的AI调用
- **特点**: 管理员权限，无需用户认证

## 使用示例

### 前端用户调用

```php
// 前端控制器调用
$aiService = ObjectManager::getInstance(AiService::class);
$response = $aiService->generate(
    $prompt,
    $modelCode,
    $scenarioCode,
    $locale,
    ['user_config' => $userConfig],
    $userId,    // 前端用户ID
    false       // 前端调用
);
```

### 后端管理员调用

```php
// 后端控制器调用
$aiService = ObjectManager::getInstance(AiService::class);
$response = $aiService->generate(
    $prompt,
    $modelCode,
    $scenarioCode,
    $locale,
    ['user_config' => $userConfig],
    null,       // 后端调用不需要用户ID
    true        // 后端调用
);
```

### 脱敏服务调用

```php
// 脱敏服务中的AI调用
$aiService = ObjectManager::getInstance(AiService::class);
$response = $aiService->generate(
    $prompt,
    $modelCode,
    $adapterCode,
    'zh_Hans_CN',
    $adapterParams,
    null,       // 后端调用
    true        // 后端调用
);
```

## 配置管理

### 模型配置
- 在后台管理界面为模型配置API密钥
- 配置存储在 `ai_model.provider_config` 字段
- JSON格式存储，包含 `api_key`, `base_url` 等

### 用户配置
- 用户可以为模型配置个人API密钥
- 存储在用户账户表中
- 优先级高于系统默认配置

### 供应商账户
- 系统默认的供应商账户
- 存储在 `ai_provider_account` 表中
- 标记 `is_default=1` 的账户作为默认账户

## 计费系统

### 前端用户计费
- 检查用户余额
- 记录使用情况
- 扣除费用
- 余额不足时提示充值

### 后端管理员
- 使用系统账户
- 记录使用日志
- 不计费

## 安全考虑

### API密钥保护
- 用户配置的API密钥仅该用户可见
- 模型配置的API密钥仅管理员可见
- 系统默认账户配置仅系统可见

### 权限控制
- 前端API需要用户认证
- 后端API需要管理员权限
- 配置访问权限分离

## 扩展性

### 新增供应商
- 实现新的Provider类
- 配置供应商账户
- 更新配置解析逻辑

### 新增场景适配器
- 实现新的Adapter类
- 配置场景参数
- 集成到AI服务中

### 新增计费方式
- 扩展计费接口
- 实现新的计费逻辑
- 更新使用记录

## 监控和日志

### 使用日志
- 记录所有AI调用
- 包含用户信息、模型信息、使用量
- 支持按用户、模型、时间查询

### 错误日志
- 记录配置解析错误
- 记录API调用失败
- 记录余额不足等业务错误

### 性能监控
- 记录API响应时间
- 监控token使用量
- 统计成功率

## 部署注意事项

1. **数据库迁移**: 确保新增字段正确创建
2. **权限配置**: 设置正确的API访问权限
3. **日志配置**: 配置日志文件路径和权限
4. **缓存清理**: 清理相关缓存确保配置生效
5. **测试验证**: 验证各优先级配置的正确性
