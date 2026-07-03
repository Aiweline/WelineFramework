<?php
declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Cms',
    __DIR__,
    '1.0.0',
    __('CMS 页面模块，基于 Theme/Meta 管理布局、可视化编辑与页面级渲染配置。')
);
