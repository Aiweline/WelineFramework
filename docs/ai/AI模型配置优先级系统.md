# AI模型配置优先级系统

## 概述

本系统实现多层级 AI 模型配置优先级管理。浏览器生成通过可恢复后台任务执行；浏览器不能提交
供应商密钥、`user_config` 或直接调用 AI 服务。

## 配置优先级

### 1. 受控 Runtime / 内部服务调用优先级

1. **模型关联的配置**
   - 后台为模型配置的默认API密钥
   - 由 Runner 在受控服务边界内解析

2. **后台默认供应商账户** (最低优先级)
   - 系统默认的供应商账户配置
   - 作为最后的备选方案

需要按用户选择账户或计费的能力只能由受控服务根据已认证 owner 解析，不能由浏览器请求直接传入。

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
- **边界**: 仅供 Runner、CLI 或模块内部服务使用；不是 HTTP/SSE 控制器的执行入口

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

### 执行入口

#### 前端可恢复任务
- **任务类型**: `ai.chat_generation`
- **启动**: `Weline.Api.resource('runtime_task').start()`
- **订阅**: `Weline.Api.createStream()` 返回的 `StreamHandle`
- **特点**: Runner 脱离 HTTP 连接执行；断线只退订，只有显式取消才请求停止

#### 后端配置操作
- **位置**: `Weline\Ai\Extends\Module\Weline_Framework\Query\AiQueryProvider`
- **功能**: 管理模型、适配器、默认模型和供应商账户
- **边界**: 不暴露直接文本、图片或流式生成功能

## 使用示例

### 前端聊天任务

```js
const api = await Weline.load('api');
const task = await api.resource('runtime_task').start({
  type_code: 'ai.chat_generation',
  input: { message, request_id: crypto.randomUUID() },
}, { silent: true });
const stream = api.createStream(task.stream_channel, {
  task_id: task.task_id,
  lease_id: task.lease_id,
});
stream.addEventListener('chunk', (event) => appendChunk(JSON.parse(event.data).chunk));
```

### 内部 Runner / CLI 调用

```php
// 不能从 HTTP 控制器直接调用。由 Runner、CLI 或模块内部服务负责。
$aiService = ObjectManager::getInstance(AiService::class);
$response = $aiService->generate(
    $prompt,
    $modelCode,
    $scenarioCode,
    $locale,
    [],
    null,
    true
);
```

### 脱敏内部服务调用

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
- 任何用户级账户或计费资料都由已认证 owner 的受控服务解析
- 浏览器任务输入不接收 API 密钥或任意 `user_config`

### 供应商账户
- 系统默认的供应商账户
- 存储在 `ai_provider_account` 表中
- 标记 `is_default=1` 的账户作为默认账户

## 计费系统

### 前端用户计费
- Runner 根据任务 owner 执行余额检查、使用记录和扣费
- 余额不足时将失败状态作为持久任务事件返回

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
- 前端 `runtime_task.start` 和订阅均绑定已认证 owner、area 与租约
- 后端配置操作需要管理员权限
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
