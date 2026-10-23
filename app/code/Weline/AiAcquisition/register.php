<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_AiAcquisition',
    __DIR__,
    '1.0.1',
    'AI 获客：潜客线索库、获客智能体、浏览器 SSE 搜索桥与模型成本台账',
    [
        'Weline_Framework',
        'Weline_Backend',
        'Weline_I18n',
        'Weline_Ai',
    ]
);
