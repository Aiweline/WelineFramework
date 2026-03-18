<?php

return [
    // 博客模块路由前缀
    // 访问示例：
    // - 博客列表：/blog/frontend/index/index
    'router' => 'blog',

    /**
     * source_keyword 写入前最大长度。255 兼容未迁移的 varchar(255)；执行 blog_post_summary_source_keyword_text 迁移后改为 0（仅保留 65535 硬顶）。
     */
    'source_keyword_db_max' => 255,
];

