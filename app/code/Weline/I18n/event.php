<?php
return [
    'Weline_I18n::collect_translations' => [
        'name' => __('收集翻译词'),
        'description' => __('当其他模块需要收集翻译词时触发。事件数据格式：["translations" => [["word" => "翻译键", "translate" => "翻译值"], ...]]。I18n模块的观察者会将翻译词存储到字典表中。'),
        'doc' => '收集翻译词.md',
    ],
    'Weline_I18n::machine_translate' => [
        'name' => __('机器翻译请求'),
        'description' => __('I18n 发布的中立机器翻译契约；AI 等可选模块可提供实现。'),
        'doc' => 'AI翻译调用.md',
    ],
];
