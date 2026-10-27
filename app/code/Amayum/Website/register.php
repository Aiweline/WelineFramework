<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Amayum_Website',
    __DIR__,
    '1.0.0',
    'Amayum 公司官网：固定页面、站点资料、SEO 事实、运行中的例子、指南与诊断询盘',
    [
        'Weline_Framework',
        'Weline_Websites',
        'Weline_Theme',
        'Weline_SystemConfig',
        'Weline_Seo',
        'Weline_Smtp',
        'Weline_ModuleRouter',
    ],
);
