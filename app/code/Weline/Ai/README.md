# Weline AI 模块

## 开发完成状态

✅ **AI模块开发已完成！**

### 已完成的功能模块

1. **✅ 核心模型层**
   - `AiModel` - AI模型数据模型
   - `AiApiKey` - API密钥管理模型
   - `AiDefaultModel` - 默认模型配置模型
   - `AiScenarioAdapter` - 场景适配器模型

2. **✅ 服务层**
   - `AiService` - 核心AI服务类（支持静态方法调用）
   - `ModelCollector` - 模型收集器服务（带保护机制）
   - `AdapterScanner` - 适配器扫描器服务
   - `DefaultModelManager` - 默认模型管理服务
   - `I18nIntegration` - 多语言集成服务
   - `TranslationService` - 翻译服务

3. **✅ 场景适配器系统**
   - `ScenarioAdapterInterface` - 适配器接口
   - `TranslationAdapter` - 翻译场景适配器
   - `CodeGenerationAdapter` - 代码生成适配器
   - 自动扫描和注册机制

4. **✅ 后台管理界面**
   - `Manager` - AI 管理聚合页（模型 | 适配器 | 供应商账户）
   - `Adapter` - 场景配置
   - `Model` - 模型管理
   - `Provider` - 供应商账户
   - `DefaultModel` - 默认模型配置

5. **✅ 可恢复聊天任务**
   - `ai.chat_generation` - 独立 Runner 中执行的聊天生成任务
   - `runtime_task` - 启动、续租、状态和明确取消
   - `StreamHandle` - 支持重连、补发和断网保护的事件订阅

6. **✅ CLI命令工具**
   - `ModelCollectCommand` - 模型收集命令
   - `AdapterScanCommand` - 适配器扫描命令
   - `DefaultModelCommand` - 默认模型管理命令

7. **✅ 完整文档**
   - 开发文档 - `doc/开发/AI模块开发文档.md`
   - 用户手册 - `doc/用户/AI模块使用手册.md`
   - 计划文档 - `AI计划.md`

### 核心特性

#### 🚀 内部服务与可恢复运行时
```php
// 仅受控 CLI、Runner 或模块内部服务可直接调用；
// 不得在 HTTP 请求或 SSE 控制器中执行后直接写入响应。
$response = AiService::generateText('你好，AI！');
```

浏览器聊天必须启动可恢复任务，再通过 `StreamHandle` 订阅事件：

```js
const api = await Weline.load('api');
const task = await api.resource('runtime_task').start({
  type_code: 'ai.chat_generation',
  input: { message: '写文章', request_id: crypto.randomUUID() }
}, { silent: true });

const stream = api.createStream(task.stream_channel, {
  task_id: task.task_id,
  lease_id: task.lease_id,
});
stream.addEventListener('chunk', (event) => renderChunk(JSON.parse(event.data).chunk));
// 页面关闭仅 stream.close() 退订；明确取消才 stream.cancel(reason)。
```

#### 🔧 场景适配器
- **翻译适配器**: 专业翻译优化
- **代码生成适配器**: 多语言代码生成
- **可扩展**: 支持自定义适配器开发

#### 🛡️ 模型保护机制
- 默认模型删除保护
- 智能模型选择和回退
- 完整的权限验证

#### 🌍 多语言支持
- 集成I18n模块
- 只通过可选的 `Weline\I18n\Api\Localization\LocaleNameCatalogInterface` 读取语言名称，不直接依赖 I18n ORM
- 支持内容本地化
- 自动语言检测

#### 📊 企业级管理
- 完整的后台管理界面
- CLI命令行工具
- 详细的统计和监控

### 使用示例

#### 内部服务使用（CLI / Runner）
```php
use Weline\Ai\Service\AiService;

// 仅受控 CLI、Runner 或模块内部服务可直接调用。
$response = AiService::generateText('介绍人工智能');

// 翻译功能
$translation = AiService::generateText(
    'Hello World',
    null,
    'translation',
    'zh-CN',
    ['target_language' => '中文']
);

// 代码生成
$code = AiService::generateText(
    '创建用户类',
    null,
    'code_generation',
    null,
    ['language' => 'php', 'style' => 'psr']
);
```

#### 浏览器聊天

浏览器或 HTTP 页面不在连接内执行生成。使用 `runtime_task.start` 启动
`ai.chat_generation`，然后使用 `Weline.Api.createStream()` 订阅任务事件；断开后重连会按事件 ID
补发，只有显式 `stream.cancel(reason)` 才会请求停止任务。

前台未登录访客也可以启动聊天任务，但 owner 必须是服务端从当前 frontend session 派生出的
`session:{session_id}`；浏览器不得自行提交或伪造 owner。已登录前台用户继续使用服务端派生的
`frontend:*` owner。

真实生成还需要至少一个已激活、连接可用的供应商账户，以及一个已配置的 `text` 默认模型。
若“使用默认模型”没有对应配置，SSE/Runtime 仍可工作，但生成任务会在模型解析阶段失败，不能
作为 SSE 故障处理。可先运行以下只读检查：

```bash
php bin/w ai:debug
php bin/w ai:model:default-model action=list
```

#### CLI管理
```bash
# 收集模型
php bin/w ai:model:collect

# 扫描适配器
php bin/w ai:adapter:scan

# 管理默认模型
php bin/w ai:model:default-model action=list
```

### 技术架构

```
Weline_Ai/
├── Model/              # 数据模型层
├── Service/            # 业务服务层
├── Adapter/            # 场景适配器
├── Controller/
│   ├── Backend/        # 后台：Manager、Adapter、Model、Provider、DefaultModel
│   └── Api/            # REST API 接口
├── Console/            # CLI 命令
├── etc/
│   └── models/        # 模型配置文件
└── doc/
    ├── 开发/
    └── 用户/
```

### 下一步

模块已完成开发，可以：

1. **部署使用**: 直接在生产环境中使用
2. **扩展功能**: 基于现有架构添加新功能
3. **自定义适配器**: 开发特定场景的适配器
4. **集成第三方**: 集成更多AI服务提供商

### 注意事项

1. **API密钥配置**: 需要配置相应AI服务的API密钥
2. **模型配置**: 根据需要添加模型配置文件
3. **权限设置**: 配置后台管理权限
4. **性能优化**: 根据使用量调整缓存策略

---

**开发完成时间**: 2024年12月
**版本**: v1.0.0
**状态**: ✅ 生产就绪

## Test Status

- 2026-05-08: Image provider response normalization and text-to-image timeout policy covered by `ProviderTimeoutPolicyTest` and `ImageGenerationResponseNormalizerTest`.
