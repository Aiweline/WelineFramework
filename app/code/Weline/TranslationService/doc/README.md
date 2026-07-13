# 翻译服务模块文档

## 概述

翻译服务模块（Weline_TranslationService）为其他模块提供统一的翻译服务接口，支持对接市面上主流的翻译服务提供商，包括Google翻译、百度翻译、DeepL、Microsoft翻译、有道翻译、腾讯翻译等。

## 核心特性

### 1. 多渠道支持
- **Google翻译**：支持100+种语言
- **百度翻译**：支持200+种语言，适合中文翻译
- **DeepL翻译**：翻译质量高，支持30+种语言
- **Microsoft翻译**：支持100+种语言
- **有道翻译**：支持100+种语言
- **腾讯翻译**：支持100+种语言

### 2. 国际化标准支持
- **ISO 639-1**：2位字母语言代码（如zh、en）
- **ISO 639-2**：3位字母语言代码（如zho、eng）
- **BCP 47**：语言标签格式（如zh-CN、en-US、zh_Hans_CN）

模块自动识别和转换不同格式的语言代码，确保兼容性。

## Dependency Inventory

- Backend、Framework 和 I18n 是必需依赖。
- ISO 639/BCP 47 转换的权威实现位于 `Weline\I18n\Api\Localization\LanguageCodeConverter`，依赖方向固定为 `TranslationService -> I18n -> Framework`。
- `Weline\TranslationService\Helper\LanguageCodeConverter` 仅是向后兼容别名；新跨模块代码不得继续引用 Helper 内部类。

### 3. 功能特性
- 单文本翻译
- 批量文本翻译
- 语言自动检测
- 翻译记录管理
- 成本统计
- 使用报表
- 渠道优先级管理
- 速率限制和每日限制

## 使用方法

### 在其他模块中使用翻译服务

```php
use Weline\Framework\Manager\ObjectManager;
use Weline\TranslationService\Api\TranslationServiceInterface;

// 获取翻译服务实例
$translationService = ObjectManager::getInstance(TranslationServiceInterface::class);

// 翻译文本
$translatedText = $translationService->translate(
    'Hello, World!',  // 要翻译的文本
    'zh',             // 目标语言（ISO 639-1格式）
    'auto',           // 源语言（auto表示自动检测）
    null,             // 指定渠道代码（null表示自动选择）
    ['module_name' => 'Weline_YourModule']  // 额外选项
);

// 批量翻译
$texts = ['Hello', 'World', 'Translation'];
$translatedTexts = $translationService->batchTranslate(
    $texts,
    'zh',
    'en'
);

// 检测语言
$detectedLanguage = $translationService->detectLanguage('Hello, World!');

// 检查是否支持语言
$isSupported = $translationService->supportsLanguage('zh');
```

### 语言代码格式

模块支持多种语言代码格式，会自动转换为ISO 639-1格式：

```php
// 以下格式都可以使用
$translationService->translate('Hello', 'zh');        // ISO 639-1
$translationService->translate('Hello', 'zh-CN');     // BCP 47
$translationService->translate('Hello', 'zh_Hans_CN'); // BCP 47变体
$translationService->translate('Hello', 'zho');       // ISO 639-2
```

## 后台管理

### 渠道配置

1. 进入后台：**系统服务** > **翻译服务** > **渠道配置**
2. 添加或编辑渠道配置：
   - 填写渠道名称和描述
   - 配置API密钥和端点
   - 设置支持的语言列表
   - 配置优先级、速率限制、成本等

### 翻译记录

1. 进入后台：**系统服务** > **翻译服务** > **翻译记录**
2. 查看翻译历史：
   - 按渠道、状态、语言筛选
   - 查看统计信息（总记录数、字符数、成本等）
   - 查看详细记录信息
   - 导出统计报表

## 配置说明

### 渠道配置字段

- **渠道代码**：系统自动生成，不可修改
- **API密钥**：从翻译服务提供商获取
- **API端点**：API接口地址（可选，留空使用默认）
- **支持的语言**：选择该渠道支持的语言列表
- **优先级**：数字越大优先级越高
- **速率限制**：每分钟最大请求数
- **每日限制**：每日最大请求数
- **每字符成本**：用于成本统计

### 语言代码支持

模块内置支持以下语言（ISO 639-1格式）：

- `zh` - 中文
- `en` - English
- `ja` - 日本語
- `ko` - 한국어
- `fr` - Français
- `de` - Deutsch
- `es` - Español
- `ru` - Русский
- `ar` - العربية
- `pt` - Português
- `it` - Italiano
- `nl` - Nederlands
- `pl` - Polski
- `tr` - Türkçe
- `vi` - Tiếng Việt
- `th` - ไทย
- `id` - Bahasa Indonesia
- `hi` - हिन्दी
- 以及更多...

## 开发指南

### 添加新的翻译渠道

1. 创建渠道适配器类，实现`ProviderInterface`接口：

```php
namespace Weline\TranslationService\Provider;

use Weline\TranslationService\Api\ProviderInterface;
use Weline\TranslationService\Model\TranslationProvider;

class YourProvider extends AbstractProvider implements ProviderInterface
{
    public function translate(
        TranslationProvider $provider,
        string $text,
        string $targetLanguage,
        string $sourceLanguage = 'auto',
        array $options = []
    ): array {
        // 实现翻译逻辑
    }
    
    public function detectLanguage(TranslationProvider $provider, string $text): string
    {
        // 实现语言检测逻辑
    }
    
    public function getProviderCode(): string
    {
        return 'your_provider';
    }
    
    public function getProviderName(): string
    {
        return 'Your Provider';
    }
    
    public function testConnection(TranslationProvider $provider): bool
    {
        // 实现连接测试逻辑
    }
}
```

2. 在`ProviderFactory`中注册渠道：

```php
// 在ProviderFactory的$adapterMap数组中添加
'your_provider' => \Weline\TranslationService\Provider\YourProvider::class,
```

3. 在安装脚本中添加默认配置：

```php
// 在Setup/Install.php的$defaultProviders数组中添加
[
    'provider_code' => 'your_provider',
    'provider_name' => 'Your Provider',
    'api_endpoint' => 'https://api.example.com/translate',
    // ...
]
```

## API接口

### TranslationServiceInterface

所有翻译服务方法都通过`TranslationServiceInterface`接口提供：

- `translate()` - 翻译文本
- `batchTranslate()` - 批量翻译
- `detectLanguage()` - 检测语言
- `supportsLanguage()` - 检查语言支持
- `getAvailableProviders()` - 获取可用渠道

## 注意事项

1. **API密钥安全**：API密钥和Secret会加密存储，请妥善保管
2. **速率限制**：建议配置速率限制和每日限制，避免超出API配额
3. **成本控制**：定期查看翻译记录和成本统计，合理使用翻译服务
4. **语言代码**：建议使用ISO 639-1格式，模块会自动转换其他格式
5. **错误处理**：翻译失败时会记录错误信息，可在翻译记录中查看

## 更新日志

### v1.0.0
- 初始版本
- 支持6个主流翻译渠道
- 国际化标准支持（ISO 639-1、ISO 639-2、BCP 47）
- 翻译记录管理和统计
- 后台管理界面

## 技术支持

如有问题或建议，请联系：
- 邮箱：aiweline@qq.com
- 网址：aiweline.com
- 论坛：https://bbs.aiweline.com
