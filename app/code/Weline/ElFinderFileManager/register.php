<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Ama所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_ElFinderFileManager',
    __DIR__,
    '1.0.0',
    'ElFinder文件管理器.你可以修改前后端的elfinder.html模板文件，修改成你想要的样式。一旦前后端存在此elfinder.html模板文件，更新或者安装不会受到影响。',
    [
        'Weline_FileManager'
    ]
);
