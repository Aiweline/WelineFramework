<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
return [
    'router' => 'module-manager',
    /** 模块卸载时 MDP 失败是否中止：1=严格中止，0=仅记录日志仍继续重命名备份（不推荐生产） */
    'module_uninstall_mdp_strict' => '1',
    /** 单表行数超过此值时 MDP 导出为 JSONL 分块（manifest schema_version=2） */
    'mdp_chunk_rows' => '10000',
];
