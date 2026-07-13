# Weline I18n 机器翻译请求契约

## 架构边界

I18n 只发布 `Weline_I18n::machine_translate` 中立事件，不加载、实例化或引用任何 AI 模块内部类。`Weline_Ai` 通过可选集成提供 `TranslationRequestObserver`；移除 AI 模块时，I18n 仍可独立启动，事件结果保持 `success=false`。

- 契约所有者：`Weline_I18n`
- 事件名：`Weline_I18n::machine_translate`
- 发布器：`Weline\I18n\Service\I18nAiTranslationAdapter`
- 可选实现：`Weline\Ai\Observer\TranslationRequestObserver`
- 旧入口：`Weline_Ai::translate` 由 Ai 模块继续监听，保持第三方兼容

## 事件数据

```php
[
    'words' => ['保存' => '保存', '取消' => '取消'],
    'source_locale' => 'zh_Hans_CN',
    'target_locale' => 'en_US',
    'strategy' => 'light',
    'translations' => [],
    'errors' => [],
    'success' => false,
]
```

I18n 在返回后负责译文校验、词典写入、语言文件发布和 Queue 续批；Ai 实现只负责把请求转交给已配置的翻译模型。
