<?php
/**
 * Weline Default Theme
 * 
 * 默认主题 - 继承 Weline_Theme，添加自定义样式
 */

use Weline\Framework\Register\Register;

Register::register(
    \Weline\Theme\Register\TypeInterface::type,
    'Weline_Default',
    [
        'name' => 'default',
        'path' => __DIR__,
        'parent' => ''
    ],
    '1.0.0',
    '默认主题 - 现代简约风格'
);
