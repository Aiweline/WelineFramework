<?php
/**
 * Weline_CustomerService 模块事件定义文件
 * 
 * 本文件定义了 Weline_CustomerService 模块提供的所有事件
 */

return [
    'Weline_CustomerService::translate' => [
        'name' => __('客服消息翻译'),
        'description' => __('客服模块通过此事件请求翻译服务。事件数据包含待翻译文本、源语言、目标语言等。翻译模块可以监听此事件并提供翻译服务。'),
        'doc' => '翻译事件.md',
    ],
];

