<?php

declare(strict_types=1);

/**
 * skill-v2 surface: module_upgrade_gate（模块版本与升级门禁）
 *
 * 可执行程序：判定注册面 -> bump etc/module.php version -> 按面跑 setup:upgrade -> 诊断 -> 记开发日志。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'module_upgrade_gate',
    'spec' => 'skill-v2',
    'label' => '模块版本与升级门禁',
    'description' => 'Model/Controller/event.xml/hook.php/register.php 等注册面变更后，必须在同一变更集上调 etc/module.php 的 version（至少 patch）并跑 setup:upgrade。用于新建模型字段、新控制器路由、注册表改动，以及「升级后没生效 / 缺列 / 404 / upgrade() 不执行」排查。见 module_version_bump_gate。',
    'authoritative_doc' => 'app/code/Weline/Framework/doc/3-开发/模块版本与升级门禁.md',
    'triggers' => [
        'model', '#[col]', '#[Col]', 'controller', 'setup:upgrade', 'module.php',
        'version', 'schema', '新建模型', '字段', '路由',
    ],
    'authoritative_docs' => [
        'app/code/Weline/Framework/doc/3-开发/模块版本与升级门禁.md',
        'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
        'app/code/Weline/Framework/doc/3-开发/模块开发完整指南.md',
    ],
    'when' => [
        '改 Model #[Col]/#[Index]/#[Table] 或新建 Model',
        '新增/变更 Controller 公开方法（新路由）',
        '改 event.xml、hook.php、register.php 等注册面',
        '升级后报缺列、404、upgrade() 不执行',
        '排除：仅改 Service/模板/i18n（通常不必 bump）',
    ],
    'inputs' => [
        '归属模块 Weline_Module 与 etc/module.php 当前 version',
        '变更面：Schema / 路由 / 注册表 / 数据迁移',
        '旧版本号与新版本号（至少 patch+1）',
    ],
    'procedure' => [
        '判定变更面是否驱动 Schema/路由/注册表；是则同一变更集准备 bump。',
        '改 etc/module.php 的 version（至少 patch+1，严格大于旧值）。',
        '按面跑：Schema→setup:upgrade -m Weline_Module；路由→setup:upgrade --route --module=<模块>；不确定→全量。',
        '确认无致命错误；缺列/漂移用 setup:schema:check [-m 模块] 诊断。',
        '在模块 doc/开发日志.md 记录归属模块、旧→新版本号与实际命令。',
    ],
    'checklist' => [
        '同一变更集里 etc/module.php version 已严格变大？',
        'Schema 变更已跑 setup:upgrade（-m 或全量）？',
        '新路由已跑 --route 后再给可访问 URL？',
        'setup:schema:check 无未解决漂移？',
        '开发日志记了版本号与命令？',
    ],
    'anti_patterns' => [
        '改 Model #[Col] 不 bump version 就期望生效',
        '加 Controller 动作不跑 --route 就交付 URL',
        '版本号没变却等 upgrade() 执行',
        '在 Setup/Upgrade.php 手改字段代替 #[Col]+bump',
        '手改 generated/ 修补 Schema 或路由',
        '改字段后只跑单测不跑 setup:upgrade',
    ],
    'evidence' => [
        'etc/module.php diff（旧→新 version）',
        'setup:upgrade / --route 输出尾部（无致命错误）',
        'doc/开发日志.md 中该次版本与命令记录',
    ],
    'norms' => [
        ['id' => 'bump_version_on_schema', 'summary' => 'Model #[Col]/#[Index]/新 Model → patch+ version + setup:upgrade。'],
        ['id' => 'bump_version_on_controller', 'summary' => '新 Controller 方法 → version + setup:upgrade --route。'],
        ['id' => 'module_version_bump_gate', 'summary' => '触 Model/Controller/event.xml/hook.php/register.php 的变更集必须含 etc/module.php version 提升（严格大于旧值），随后跑 setup:upgrade 或 --route；仅改 Service/模板/i18n 通常不必 bump。'],
    ],
    'verification_commands' => [
        'php bin/w setup:schema:check',
        'php bin/w setup:upgrade',
    ],
    // 兼容别名：本体已并入 checklist / anti_patterns / norms；保留契约测试锁定的旧禁项原文。
    'template_surface_rules' => [
        'required' => [],
        'forbidden' => [
            'Changing Model/Controller/event/hook/register without bumping etc/module.php version in the same change set',
        ],
    ],
];
