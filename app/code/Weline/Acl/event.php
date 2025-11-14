<?php
return [
    'Weline_Acl::no_access_redirect_before' => [
        'name' => __('无权限访问重定向前'),
        'description' => __('在用户无权限访问时，执行重定向操作前触发。允许其他模块在重定向前执行自定义操作，如记录日志、发送通知等。'),
        'doc' => '无权限访问重定向前.md',
    ],
    'Weline_Acl::check_role' => [
        'name' => __('角色检查'),
        'description' => __('在检查用户角色权限时触发，允许其他模块自定义角色检查逻辑。'),
        'doc' => '角色检查.md',
    ],
];

