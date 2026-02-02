<?php
/**
 * WeShop Default Theme
 * 
 * WeShop默认主题 - 继承 Weline_Theme，添加电商专用样式和布局
 */

use Weline\Framework\Register\Register;

Register::register(
    \Weline\Theme\Register\TypeInterface::type,
    'WeShop_Default',
    [
        'name' => 'weshop-default',
        'path' => __DIR__,
        'parent' => 'default'  // 继承自 Weline 默认主题
    ],
    '1.0.0',
    'WeShop默认主题 - 电商专用主题'
);
