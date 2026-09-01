<?php
return [
    'Weline_I18n::collect_translations' => [
        'name' => __('收集翻译词（已废弃）'),
        'description' => __('@deprecated 请改用 Weline_Framework_Phrase::dictionary_register。本事件仍由 CollectTranslationsShimObserver 转发至 Framework 契约。'),
        'doc' => '收集翻译词.md',
    ],
    'Weline_I18n::machine_translate' => [
        'name' => __('机器翻译请求'),
        'description' => __('I18n 发布的中立机器翻译契约；AI 等可选模块可提供实现。'),
        'doc' => 'AI翻译调用.md',
    ],
    'Weline_I18n::locale_catalog_changed' => [
        'name' => __('语言目录变更'),
        'description' => __('后台安装、激活、停用或卸载语言，以及国家语言批量同步后触发。维护模块等可据此刷新零依赖静态页。'),
        'doc' => '',
    ],
];
