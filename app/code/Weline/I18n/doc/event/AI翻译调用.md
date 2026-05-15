# Weline I18n 模块 - AI翻译调用事件监听

## 概述

I18n 自动翻译继续复用 `Weline_Ai::translate` 事件链，但该事件现在只作为 AI 适配链的一环。词典扫描、入队、续批和发布由 I18n Queue 服务负责。

## 事件职责

- 事件名：`Weline_Ai::translate`
- 提供模块：`Weline_Ai`
- 监听模块：`Weline_I18n`
- 监听配置：`app/code/Weline/I18n/etc/event.xml`
- 观察者：`Weline\I18n\Observer\AiTranslationObserver`

`I18nAiTranslationAdapter` 负责派发事件并读取返回结果。`AiTranslationObserver` 只负责把事件请求转交给 `Weline\Ai\Service\TranslationService::batchTranslate()`，并把 `translations`、`success`、`errors` 写回事件数据。

## 事件数据

```php
[
    'words' => ['保存', '取消'],
    'source_locale' => 'zh_Hans_CN',
    'target_locale' => 'en_US',
    'strategy' => 'light',
    'translations' => [],
    'errors' => [],
    'success' => false,
]
```

## 调用边界

- I18n 不直接管理 AI 提供商、密钥或默认模型。
- AI 场景配置继续由 `Weline_Ai` 的 `translation` 场景负责。
- I18n 在事件返回后负责译文校验、词典写入、发布和续批队列。
- 不再由 I18n Cron 直接触发 `Weline_Ai::translate`。

## 相关类

- `Weline\I18n\Service\I18nAiTranslationAdapter`
- `Weline\I18n\Observer\AiTranslationObserver`
- `Weline\Ai\Service\TranslationService`
- `Weline\I18n\Queue\AiTranslateQueue`
