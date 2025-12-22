<?php
return [
    'Weline_Ai::ai_monitoring_alert' => [
        'name' => __('AI监控告警'),
        'description' => __('在AI模型监控触发告警时触发，允许其他模块监听并处理告警通知。事件数据包含告警信息、监控数据、模型代码、租户ID等。'),
        'doc' => 'AI监控告警.md',
    ],
    'Weline_Ai::translate' => [
        'name' => __('AI翻译调用'),
        'description' => __('其他模块可以通过触发此事件调用AI进行翻译。支持批量翻译和增量翻译。事件数据包含待翻译词列表、目标语言、源语言等。'),
        'doc' => 'AI翻译调用.md',
    ],
];

