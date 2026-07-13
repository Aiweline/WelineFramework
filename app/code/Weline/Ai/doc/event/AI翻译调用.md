# Weline AI 模块 - 机器翻译事件实现

## 概述

机器翻译的中立契约为 `Weline_I18n::machine_translate`，由 I18n 拥有。AI 模块通过 `TranslationRequestObserver` 提供可选实现，并继续监听 `Weline_Ai::translate` 作为一版兼容入口。新代码应优先通过 I18n 适配器调用。

## 事件列表

### 1. Weline_Ai::translate - AI翻译调用事件

#### 基本信息

- **事件名称**：`Weline_Ai::translate`
- **事件类型**：AI翻译调用
- **提供模块**：`Weline_Ai`
- **配置文件**：`app/code/Weline/Ai/etc/event.xml`

#### 功能说明

其他模块可以通过触发此事件来调用AI进行翻译。AI模块会使用配置的翻译模型进行批量翻译，并将翻译结果通过事件数据返回。支持增量翻译（跳过已有翻译的词）和批量翻译（一次性翻译多个词）。

#### 使用场景

- I18n模块批量翻译词典
- 多语言内容自动翻译
- 用户内容翻译
- 商品描述翻译
- 文档翻译

#### 使用方法

##### 基本用法

```php
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

// 获取事件管理器实例
/** @var EventsManager $eventsManager */
$eventsManager = ObjectManager::getInstance(EventsManager::class);

// 准备待翻译的词列表
$words = [
    '首页',
    '用户中心',
    '购物车',
    '订单管理'
];

// 创建事件数据
$eventData = [
    'words' => $words,                          // 必填：待翻译词列表
    'target_locale' => 'en_US',                 // 必填：目标语言代码
    'source_locale' => 'zh_Hans_CN',           // 可选：源语言代码，默认 'auto'
    'strategy' => 'light',                      // 可选：翻译策略 light/high_fidelity，默认 'light'
    'translations' => [],                       // 输出：翻译结果（事件处理后填充）
    'errors' => [],                            // 输出：错误信息（事件处理后填充）
    'success' => false                         // 输出：是否成功（事件处理后填充）
];

// 触发翻译事件
$eventsManager->dispatch('Weline_Ai::translate', $eventData);

// 获取翻译结果
if ($eventData['success']) {
    $translations = $eventData['translations'];
    // $translations 格式：['首页' => 'Home', '用户中心' => 'User Center', ...]
    
    foreach ($translations as $word => $translation) {
        echo "{$word} => {$translation}\n";
    }
} else {
    // 处理错误
    $errors = $eventData['errors'];
    foreach ($errors as $error) {
        echo "错误: {$error}\n";
    }
}
```

##### 批量翻译示例

```php
// 批量翻译1000个词
$words = []; // 准备1000个待翻译的词
for ($i = 1; $i <= 1000; $i++) {
    $words[] = "词汇 {$i}";
}

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
    echo "成功翻译 " . count($eventData['translations']) . " 个词\n";
}
```

#### 数据格式说明

##### 输入参数

| 字段 | 类型 | 必填 | 说明 | 默认值 |
|------|------|------|------|--------|
| words | array | 是 | 待翻译词列表，字符串数组 | - |
| target_locale | string | 是 | 目标语言代码，如 'en_US', 'ja_JP' | - |
| source_locale | string | 否 | 源语言代码，'auto' 表示自动检测 | 'auto' |
| strategy | string | 否 | 翻译策略：'light' (快速) 或 'high_fidelity' (高质量) | 'light' |

##### 输出参数

| 字段 | 类型 | 说明 |
|------|------|------|
| translations | array | 翻译结果，键值对数组，键为原词，值为翻译 |
| errors | array | 错误信息列表，字符串数组 |
| success | bool | 是否翻译成功 |

#### 翻译策略说明

- **light**（轻量翻译）
  - 快速翻译，适合大量文本
  - 成本较低
  - 适用场景：批量词典翻译、简单文本翻译

- **high_fidelity**（高保真翻译）
  - 高质量翻译，保持语气和风格
  - 成本较高
  - 适用场景：重要内容翻译、营销文案翻译

#### 异常处理

当翻译过程中发生异常时：

1. `success` 字段会被设置为 `false`
2. `errors` 数组会包含具体的错误信息
3. 系统会自动发送系统消息通知管理员（通过 `Weline_Admin::msg` 事件）

错误消息示例：

```php
if (!$eventData['success']) {
    // 错误信息格式
    $errors = [
        '未配置AI翻译模型',
        'API调用失败：网络超时',
        // ...
    ];
}
```

#### 系统消息通知

翻译过程中的重要事件会自动发送系统消息：

- 翻译失败时：发送错误通知
- 批量翻译完成时：发送成功通知（包含翻译数量统计）
- API异常时：发送异常详情通知

#### 注意事项

1. **批量大小**：建议每次翻译不超过1000个词，以避免API超时
2. **频率限制**：注意AI模型的调用频率限制和配额
3. **成本控制**：批量翻译会产生API调用成本，请合理使用
4. **缓存机制**：翻译结果会被缓存，相同内容不会重复翻译
5. **增量翻译**：配合I18n模块使用时，已存在的翻译会自动跳过

#### 最佳实践

1. **使用定时任务**：对于大量翻译，建议使用定时任务分批处理
2. **错误重试**：翻译失败时，可以实现重试机制
3. **进度跟踪**：对于大批量翻译，建议记录翻译进度
4. **质量检查**：重要内容建议人工审核翻译结果

#### 示例：I18n模块集成

```php
// I18n模块中使用AI翻译事件
class TranslationCronTask implements CronTaskInterface
{
    public function execute(): string
    {
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        
        // 获取待翻译的词（未翻译的词）
        $words = $this->getUntranslatedWords('en_US', 1000);
        
        if (empty($words)) {
            return "没有待翻译的词";
        }
        
        // 触发AI翻译事件
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
            // 保存翻译结果到词典
            $this->saveTranslations($eventData['translations'], 'en_US');
            return "成功翻译 " . count($eventData['translations']) . " 个词";
        } else {
            return "翻译失败: " . implode(', ', $eventData['errors']);
        }
    }
}
```

## 相关文档

- [AI模块开发文档](../README.md)
- [I18n模块集成指南](../../../I18n/doc/README.md)
- [事件系统使用指南](../../../Framework/doc/event.md)

## 更新记录

- 2025-12-06: 创建文档，定义AI翻译调用事件接口
