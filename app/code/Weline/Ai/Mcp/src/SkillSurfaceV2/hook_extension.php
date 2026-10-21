<?php

declare(strict_types=1);

/**
 * skill-v2 surface: hook_extension（Hook 扩展规范）
 *
 * 可执行程序：定 Owner -> 五段命名（type∈{partials,layouts}）-> hook.php -> doc/hook -> 实现模板 -> setup:upgrade --route。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'hook_extension',
    'spec' => 'skill-v2',
    'label' => 'Hook 扩展规范',
    'description' => 'Hook 三件套：Owner hook.php 声明 + doc/hook/*.md 规约 + 实现模块 view/hooks/*.phtml；名字五段，第三段 type 只能是 partials 或 layouts。用于新增/改 Hook 点位、给现有 Hook 补规约，以及 setup:upgrade 报「Hook 规约缺失」。见 hook_triple、hook_name_type_partial_or_layout。',
    'authoritative_doc' => 'app/code/Weline/Hook/doc/Hook创建规范.md',
    'triggers' => [
        'hook', 'view/hooks', 'hook.php', 'w:hook', '<w:hook',
    ],
    'authoritative_docs' => [
        'app/code/Weline/Hook/doc/Hook创建规范.md',
        'app/code/Weline/Theme/doc/Hook使用指南.md',
        'app/code/Weline/Theme/doc/Hook点位索引.md',
    ],
    'when' => [
        '新增 Hook 点或实现 view/hooks/*.phtml',
        '给现有 Hook 补 hook.php 条目或 doc/hook 文档',
        'setup:upgrade 报 Hook 规约缺失/致命中断',
        '第三方模块要扩展 Theme 布局槽',
        '排除：通用事件（走 event_extension）',
    ],
    'inputs' => [
        'Owner 模块（声明 Hook 名）与实现模块',
        'Hook 五段名：Module::area::type::component::position',
        '实现模板相对路径 view/hooks/{HookPath}.phtml',
    ],
    'procedure' => [
        '定 Owner：Hook 名由定义方模块在 hook.php 声明；实现可挂在他模块 view/hooks。',
        '按五段命名，第三段只能 partials 或 layouts，页面/功能名进 component 或 position。',
        '写 hook.php 条目（name/description/doc），doc 指向 doc/hook/{doc}.md。',
        '写 doc/hook/{doc}.md：Hook 名、用途、实现路径示例。',
        '建实现模板 view/hooks/{HookPath}.phtml（:: → /），文件头写 Hook 元数据。',
        '跑 php bin/w setup:upgrade --route，失败按「Hook 规约缺失」补全。',
    ],
    'checklist' => [
        'Owner hook.php 有条目且 doc 路径与 doc/hook/ 实际一致？',
        'doc/hook/*.md 已写且早于 setup:upgrade？',
        '名字五段，第三段是 partials 或 layouts？',
        '实现模板路径符合 :: → / 规则？',
        'setup:upgrade --route 通过？',
    ],
    'anti_patterns' => [
        '只有 view/hooks/*.phtml，Owner 无 hook.php 条目',
        'hook.php 的 doc 字段与 doc/hook 实际路径不一致',
        '第三段写 theme-editor/checkout 等功能名',
        '在实现方 hook.php 声明他人拥有的 Theme 布局 Hook',
        '用 Hook 却未在 setup:upgrade 前补 doc',
    ],
    'evidence' => [
        'hook.php diff + doc/hook/*.md 路径',
        'setup:upgrade --route 输出（无 Hook 规约缺失）',
    ],
    'norms' => [
        ['id' => 'hook_triple', 'summary' => 'Owner hook.php + doc/hook/*.md + 实现 view/hooks/*.phtml 三件套缺一即 setup:upgrade 致命中断；Hook 名由 Owner 声明，实现可在其他模块挂载。'],
        ['id' => 'hook_name_five_segment', 'summary' => '{Module}::{frontend|backend}::{partials|layouts}::{component}::{position}；type 只能是 partials 或 layouts，页面/功能名放 component 或 position；短名 Hook 由 Theme 在 hook.php 声明。'],
    ],
    'verification_commands' => [
        'php bin/w setup:upgrade --route',
    ],
    // 兼容别名：本体已并入 checklist / anti_patterns / norms；保留契约测试锁定的旧禁项原文。
    'template_surface_rules' => [
        'required' => [],
        'forbidden' => [
            'Inventing type segment (theme-editor, checkout, product, account, …) — type is ONLY partials or layouts',
        ],
    ],
];
