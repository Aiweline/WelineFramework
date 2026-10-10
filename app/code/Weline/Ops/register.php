<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Ops',
    __DIR__,
    '1.0.0',
    '网站 AI 运营中枢：调度受理、会话任务账本、异步派工博客/社媒、审批外发',
    ['Weline_Backend', 'Weline_Admin', 'Weline_Ai'],
);
