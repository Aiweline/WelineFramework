<?php

declare(strict_types=1);

/**
 * skill-v2 surface: event_extension（Event 扩展规范）
 *
 * 可执行程序：检索现有事件 -> 扩展点选型 -> Observer + event.xml -> doc/event -> setup:upgrade。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'event_extension',
    'spec' => 'skill-v2',
    'label' => 'Event 扩展规范',
    'description' => 'Observer + etc/event.xml + doc/event/*.md（+ 触发方说明）四件套；事件名必须先在 event 索引/文档中可查，禁止发明未文档化事件名。用于新增 Observer、选扩展点与事件索引升级；跨模块读走 QueryProvider、写走归属 Interface/Hook/Queue。见 event_documented。',
    'authoritative_doc' => 'app/code/Weline/Framework/doc/3-开发/事件命名与注册规范.md',
    'triggers' => [
        'event', 'observer', 'event.xml', 'dispatch', 'EventsManager',
    ],
    'authoritative_docs' => [
        'app/code/Weline/Framework/doc/3-开发/事件命名与注册规范.md',
        'app/code/Weline/Framework/doc/event/README.md',
        'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
    ],
    'when' => [
        '新增/改 Observer 或事件注册',
        '新建事件名或事件 payload 契约',
        '判断旁路扩展该用 Event 还是 Query/Hook',
        '事件列表为空/不触发，需排查注册',
        '排除：跨模块读（QueryProvider）、UI 控件（Taglib/Hook）',
    ],
    'inputs' => [
        '归属模块与事件名（含域）',
        'payload 字段与触发时机',
        'event/README.md 或 doc/event 中已文档化的事件',
    ],
    'procedure' => [
        '先检索 event/README.md 与 Framework/doc/event/：有现成事件就复用。',
        '选型：通知/日志/清缓存/旁路扩展用 Event；跨模块读走 QueryProvider，写走归属 Interface/Hook/Queue。',
        '建 Observer（Observer/{Name}.php）+ 注册 etc/event.xml，类名与 instance 一致。',
        '写 doc/event/{topic}.md：事件名、时机、payload 形状与示例。',
        'dispatch 处注释或归属模块开发日志引用事件名与 payload。',
        '跑 php bin/w setup:upgrade 让 Observer 注册生效。',
    ],
    'checklist' => [
        '事件名已在 event 索引/doc 中存在，无重名冲突？',
        'event.xml 与 Observer 类名一致？',
        'doc/event/*.md 写清时机与 payload？',
        '未用跨模块 new Service 代替 Event？',
        'setup:upgrade 后事件列表可见？',
    ],
    'anti_patterns' => [
        'dispatch 未文档化的事件名',
        '跨模块 new 对方 Service 代替 Event',
        '只加 Observer 不写 doc/event/*.md',
        'event.xml 注册类名与 Observer 不一致',
        '用 Event 承载跨模块读或业务写',
    ],
    'evidence' => [
        'Observer 路径 + etc/event.xml diff',
        'doc/event/*.md 路径与 payload 定义',
        'setup:upgrade 后事件列表输出',
    ],
    'norms' => [
        ['id' => 'documented_event_name', 'summary' => '事件名必须在 doc/event 或 Framework event 索引中文档化（见 event_documented）；命名按 {Module}::{domain} 或 {Module}_{Sub}::{action} 并以已有文档为准；payload 形状写清；新建前先检索，禁止静默发明。'],
    ],
    'verification_commands' => [
        'php bin/w setup:upgrade',
    ],
    // 兼容别名：本体已并入 checklist / anti_patterns / norms，旧模板规则清单不再重复渲染。
    'template_surface_rules' => [
        'required' => [],
        'forbidden' => [],
    ],
];
