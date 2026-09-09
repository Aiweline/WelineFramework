<?php

declare(strict_types=1);

/**
 * 建站助手悬浮部件（目录登记）。
 * 实际全后台挂载走 Theme backend base::body-end hook（同客服悬浮），避免仅 Dashboard 可见。
 */
return [
    'site-setup-assistant-float' => [
        'name' => '建站助手（悬浮）',
        'description' => '按站点展示上线/迁站贴士与任务进度；右下角悬浮，完成后不展示。由 backend base::body-end hook 挂载。',
        'type' => 'float',
        'code' => 'site-setup-assistant-float',
        'area' => 'backend',
        'template' => 'Weline_SiteSetupAssistant::templates/backend/widgets/site-setup-assistant-float.phtml',
        'page_layouts' => ['default', 'dashboard', 'fullscreen'],
        'position' => ['content'],
        'slot' => '',
        'supports' => [
            'backend-shell-float',
            'site-setup-assistant',
        ],
    ],
];
