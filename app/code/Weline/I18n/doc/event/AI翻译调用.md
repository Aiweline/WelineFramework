# Weline I18n 模块 - AI翻译调用事件监听

## 概述

本文档说明了 I18n 模块如何监听和处理 `Weline_Ai::translate` 事件，实现通过事件驱动的AI翻译调用机制。

**重要说明**：
- `Weline_Ai::translate` 事件由 `Weline_Ai` 模块定义和提供
- `Weline_I18n` 模块通过事件观察者监听该事件
- 事件定义在 `app/code/Weline/Ai/event.php`
- 事件监听在 `app/code/Weline/I18n/etc/event.xml`

## 事件监听

### 1. Weline_Ai::translate - AI翻译调用事件

#### 基本信息

- **事件名称**：`Weline_Ai::translate`
- **提供模块**：`Weline_Ai`（在该模块的 event.php 中定义）
- **监听模块**：`Weline_I18n`（在该模块的 etc/event.xml 中监听）
- **观察者类**：`Weline\I18n\Observer\AiTranslationObserver`
- **配置文件**：`app/code/Weline/I18n/etc/event.xml`

#### 功能说明

I18n 模块监听 `Weline_Ai::translate` 事件，当其他模块触发此事件时，I18n 模块会调用 Ai 模块的 `TranslationService` 进行实际翻译，并将翻译结果通过事件数据返回。

#### 事件定义（在 Weline_Ai 模块中）

```php
// app/code/Weline/Ai/event.php
<?php
return [
    'Weline_Ai::translate' => [
        'name' => __('AI翻译调用'),
        'description' => __('其他模块可以通过触发此事件调用AI进行翻译...'),
        'doc' => 'AI翻译调用.md',
    ],
];
```

#### 事件监听（在 Weline_I18n 模块中）

```xml
<!-- app/code/Weline/I18n/etc/event.xml -->
<event name="Weline_Ai::translate">
    <observer name="Weline_I18n::ai_translation" 
              instance="Weline\I18n\Observer\AiTranslationObserver" 
              disabled="false" 
              shared="true" 
              sort="0"/>
</event>
```

#### 观察者实现

```php
namespace Weline\I18n\Observer;

use Weline\Ai\Service\TranslationService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class AiTranslationObserver implements ObserverInterface
{
    private TranslationService $translationService;

    public function execute(Event &$event): void
    {
        // 获取事件数据
        $words = $event->getData('words');
        $targetLocale = $event->getData('target_locale');
        $sourceLocale = $event->getData('source_locale') ?? 'auto';
        $strategy = $event->getData('strategy') ?? 'light';

        // 调用AI翻译服务
        $translations = $this->translationService->batchTranslate(
            $words,
            $targetLocale,
            $sourceLocale,
            $strategy
        );

        // 设置翻译结果
        $event->setData('translations', $translations);
        $event->setData('success', true);
    }
}
```

## 使用示例

### 基本用法

```php
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

// 获取事件管理器
$eventsManager = ObjectManager::getInstance(EventsManager::class);

// 准备待翻译的词
$words = ['首页', '用户中心', '购物车'];

// 创建事件数据
$eventData = [
    'words' => $words,
    'target_locale' => 'en_US',
    'source_locale' => 'zh_Hans_CN',
    'strategy' => 'light',
    'translations' => [],
    'errors' => [],
    'success' => false
];

// 触发翻译事件
$eventsManager->dispatch('Weline_Ai::translate', $eventData);

// 获取翻译结果
if ($eventData['success']) {
    $translations = $eventData['translations'];
    // $translations = ['首页' => 'Home', '用户中心' => 'User Center', ...]
}
```

### 在定时任务中使用

```php
// app/code/Weline/I18n/Cron/AiTranslation.php
class AiTranslation implements CronTaskInterface
{
    public function execute(): string
    {
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        
        // 获取未翻译的词
        $words = $this->getUntranslatedWords('en_US', 1000);
        
        // 触发翻译事件
        $eventData = [
            'words' => $words,
            'target_locale' => 'en_US',
            'source_locale' => 'zh_Hans_CN',
            'strategy' => 'light',
            'translations' => [],
            'errors' => [],
            'success' => false
        ];
        
        $eventsManager->dispatch('Weline_Ai::translate', $eventData);
        
        if ($eventData['success']) {
            // 保存翻译结果
            $this->saveTranslations($eventData['translations'], 'en_US');
        }
        
        return "翻译完成";
    }
}
```

## 异常处理

### 异常捕获

观察者会捕获所有异常，并通过事件数据返回错误信息：

```php
if (!$eventData['success']) {
    $errors = $eventData['errors'];
    foreach ($errors as $error) {
        echo "错误: {$error}\n";
    }
}
```

### 系统消息通知

当翻译过程发生异常时，观察者会自动发送系统消息通知：

- **翻译失败**：发送错误详情
- **翻译异常**：发送异常信息和位置
- **参数错误**：发送参数验证错误

## 相关功能

### 定时任务

I18n 模块提供了 AI 批量翻译定时任务：

- **任务名称**：`i18n_ai_translation`
- **执行频率**：每小时执行一次
- **功能**：批量翻译词典（每次1000个词）
- **特性**：增量翻译（已存在的翻译不会重新翻译）

### CSV导入

I18n 模块提供了 CSV 词典导入功能：

```php
use Weline\I18n\Service\AiTranslationService;

$service = ObjectManager::getInstance(AiTranslationService::class);

// 导入单个CSV文件
$result = $service->importFromCsv('/path/to/en_US.csv', 'en_US');

// 导入所有模块的CSV文件
$result = $service->importModuleCsvFiles('en_US');
```

## 工作流程

1. **定时任务触发**：每小时执行一次 AI 翻译定时任务
2. **获取待翻译词**：从词典中获取未翻译的词（最多1000个）
3. **触发翻译事件**：通过 `Weline_Ai::translate` 事件调用AI翻译
4. **观察者处理**：I18n 观察者调用 Ai 模块的 TranslationService
5. **批量翻译**：AI 服务一次性翻译所有词（而不是循环单个翻译）
6. **保存结果**：将翻译结果保存到词典数据库
7. **发送通知**：翻译完成或失败时发送系统消息通知

## 最佳实践

1. **批量翻译**：每次翻译1000个词，避免API超时
2. **增量翻译**：只翻译未翻译的词，避免重复翻译
3. **异常处理**：捕获所有异常，发送系统消息通知
4. **CSV导入**：先导入已有的翻译，减少AI翻译成本
5. **定时任务**：使用定时任务自动化翻译流程

## 相关文档

- [AI翻译调用事件](../../../Ai/doc/event/AI翻译调用.md)
- [I18n模块开发文档](../README.md)
- [定时任务开发指南](../../../Cron/doc/README.md)

