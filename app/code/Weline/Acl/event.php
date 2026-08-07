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
    'Weline_Acl::role_acl_entries_after' => [
        'name' => __('角色 ACL 条目加载后'),
        'description' => __('角色有效 ACL 行从库加载后触发，观察者可写回 entries（如按站级授权包求交）。Acl 不感知 Website。'),
        'doc' => '站级能力天花板.md',
    ],
    'Weline_Acl::super_admin_bypass_check' => [
        'name' => __('超管 ACL 旁路检查'),
        'description' => __('role_id=1 短路放行前触发；观察者可将 allow_bypass 置为 false 以关闭旁路。'),
        'doc' => '站级能力天花板.md',
    ],
    'Weline_Acl::role_access_save_before' => [
        'name' => __('角色权限保存前'),
        'description' => __('角色 ACL 分配保存前触发；观察者可拒绝越权 source_ids（allowed/message）。'),
        'doc' => '站级能力天花板.md',
    ],
    'Weline_Acl::role_listing_filter' => [
        'name' => __('角色列表过滤'),
        'description' => __('角色列表/创建前触发；观察者可按 website_id 约束可管理角色集合。'),
        'doc' => '站级能力天花板.md',
    ],
    'Weline_Acl::acl_assignment_rows_after' => [
        'name' => __('ACL 分配树行加载后'),
        'description' => __('权限分配树/标签行加载后触发；观察者可写回 rows 以裁剪可分配资源。'),
        'doc' => '站级能力天花板.md',
    ],
];
