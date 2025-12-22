<?php
return [
    // ========== 核心翻译事件 ==========
    'Weline_TranslationService::translate' => [
        'name' => __('翻译文本'),
        'description' => __('翻译文本事件，其他模块可以通过触发此事件来使用翻译服务。观察者会执行翻译并设置 translated_text 字段。'),
        'doc' => 'event/翻译事件.md',
    ],
    'Weline_TranslationService::batch_translate' => [
        'name' => __('批量翻译'),
        'description' => __('批量翻译事件，其他模块可以通过触发此事件来批量翻译多个文本。观察者会执行批量翻译并设置 translated_texts 字段。'),
        'doc' => 'event/翻译事件.md',
    ],
    'Weline_TranslationService::detect_language' => [
        'name' => __('检测语言'),
        'description' => __('语言检测事件，其他模块可以通过触发此事件来检测文本的语言。观察者会执行语言检测并设置 detected_language 字段。'),
        'doc' => 'event/翻译事件.md',
    ],
    
    // ========== 生命周期事件 ==========
    'Weline_TranslationService::translate_before' => [
        'name' => __('翻译前'),
        'description' => __('翻译前事件，在调用翻译服务前触发，允许其他模块修改翻译参数（如源语言、目标语言、渠道等）。'),
        'doc' => 'event/生命周期事件.md',
    ],
    'Weline_TranslationService::translate_after' => [
        'name' => __('翻译后'),
        'description' => __('翻译后事件，在翻译完成后触发，允许其他模块修改翻译结果或执行后处理操作。'),
        'doc' => 'event/生命周期事件.md',
    ],
    'Weline_TranslationService::translate_error' => [
        'name' => __('翻译错误'),
        'description' => __('翻译错误事件，在翻译失败时触发，允许其他模块处理错误、实现降级方案等。'),
        'doc' => 'event/生命周期事件.md',
    ],
    'Weline_TranslationService::batch_translate_before' => [
        'name' => __('批量翻译前'),
        'description' => __('批量翻译前事件，在调用批量翻译服务前触发，允许其他模块修改批量翻译参数。'),
        'doc' => 'event/生命周期事件.md',
    ],
    'Weline_TranslationService::batch_translate_after' => [
        'name' => __('批量翻译后'),
        'description' => __('批量翻译后事件，在批量翻译完成后触发，允许其他模块修改翻译结果或执行后处理操作。'),
        'doc' => 'event/生命周期事件.md',
    ],
    'Weline_TranslationService::batch_translate_error' => [
        'name' => __('批量翻译错误'),
        'description' => __('批量翻译错误事件，在批量翻译失败时触发，允许其他模块处理错误、实现降级方案等。'),
        'doc' => 'event/生命周期事件.md',
    ],
];

