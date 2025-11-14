<?php
return [
    // 动态事件：使用 {action} 表示动态操作，可以匹配 Weline_Queue::add、Weline_Queue::edit 等
    'Weline_Queue::{action}' => [
        'name' => __('队列操作'),
        'description' => __('在队列执行操作时触发，允许其他模块监听队列操作。action 可以是 add、edit 等。'),
        'doc' => '队列操作.md',
    ],
    'Weline_Queue::delete' => [
        'name' => __('队列删除'),
        'description' => __('在删除队列时触发，允许其他模块监听队列删除操作。'),
        'doc' => '队列删除.md',
    ],
    'Weline_Queue::reset' => [
        'name' => __('队列重置'),
        'description' => __('在重置队列时触发，允许其他模块监听队列重置操作。'),
        'doc' => '队列重置.md',
    ],
    'Weline_Queue::stop' => [
        'name' => __('队列停止'),
        'description' => __('在停止队列时触发，允许其他模块监听队列停止操作。'),
        'doc' => '队列停止.md',
    ],
    'Weline_Queue::continue' => [
        'name' => __('队列继续'),
        'description' => __('在继续队列时触发，允许其他模块监听队列继续操作。'),
        'doc' => '队列继续.md',
    ],
];

