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

5. **✅ API接口**
   - `Chat` - 聊天API控制器
   - 支持流式和非流式响应
   - 完整的错误处理和参数验证

6. **✅ CLI命令工具**
   - `ModelCollectCommand` - 模型收集命令
   - `AdapterScanCommand` - 适配器扫描命令
   - `DefaultModelCommand` - 默认模型管理命令

7. **✅ 完整文档**
   - 开发文档 - `doc/开发/AI模块开发文档.md`
   - 用户手册 - `doc/用户/AI模块使用手册.md`
   - 计划文档 - `AI计划.md`

### 核心特性

#### 🚀 双服务模式
```php
// PHP静态方法模式
$response = AiService::generateText('你好，AI！');

// 流式生成
AiService::generateTextStream('写文章', function($chunk) {
    echo $chunk;
});
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

#### 基本使用
```php
use Weline\Ai\Service\AiService;

// 简单文本生成
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

#### API调用
```bash
curl -X POST http://your-domain/ai/api/chat/generate \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "你好，AI！",
    "scenario_code": "text_generation"
  }'
```

#### CLI管理
```bash
# 收集模型
php bin/w ai:model:collect

# 扫描适配器
php bin/w ai:adapter:scan

# 管理默认模型
php bin/w ai:default-model:manage --action=list
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
