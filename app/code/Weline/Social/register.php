<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Social',
    __DIR__,
    '1.0.0',
    '融媒体社媒管理模块，提供统一平台账户、AI 创意和多平台发布能力',
    ['Weline_Framework', 'Weline_Backend', 'Weline_Frontend', 'Weline_I18n', 'Weline_Ai', 'Weline_Queue']
);

