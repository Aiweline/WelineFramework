<?php

declare(strict_types=1);

return [
    'Weline_Backend::application::system_notification' => [
        'description' => '系统通知事件',
        'data' => [
            'topic'         => '消息主题标识',
            'type'          => '消息类型：info/success/warning/error/urgent',
            'title'         => '消息标题',
            'content'       => '消息内容',
            'priority'      => '优先级 1-10',
            'metadata'      => '扩展数据数组',
            'is_icon'       => '是否使用图标',
            'avatar'        => '图标类名或图片 URL',
            'notify_users'  => '指定通知的用户 ID 列表',
            'source_module' => '来源模块',
        ],
    ],

    'Weline_Backend::user::registered' => [
        'description' => '后台用户注册/创建事件',
        'data' => [
            'user_id'  => '用户 ID',
            'username' => '用户名',
            'email'    => '用户邮箱',
            'phone'    => '用户手机（可选）',
            'is_new'   => '是否新创建的用户',
        ],
    ],
];
