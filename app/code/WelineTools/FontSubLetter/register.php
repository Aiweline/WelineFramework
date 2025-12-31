<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'WelineTools_FontSubLetter',
    __DIR__,
    '1.0.0',
    '字体字符提取与压缩工具模块',
    [
        'Weline_Framework',
        'Weline_FileManager'
    ]
);
