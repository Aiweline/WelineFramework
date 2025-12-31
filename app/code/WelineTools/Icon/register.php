<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WelineTools_Icon',
    __DIR__,
    '1.0.0',
    '图标工具模块 - 上传、压缩、格式转换，方便制作ICO网页图标',
    [
        'Weline_Framework'
    ]
);

