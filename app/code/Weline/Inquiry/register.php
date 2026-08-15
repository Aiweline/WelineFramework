<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Inquiry',
    __DIR__,
    '1.0.0',
    '通用多语言询盘表单模块，提供版本化表单、提交归档、Taglib 与 Widget 嵌入能力',
    ['Weline_Framework', 'Weline_Backend', 'Weline_I18n', 'Weline_Taglib', 'Weline_Widget']
);
