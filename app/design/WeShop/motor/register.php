<?php
/**
 * WeShop Motor Theme
 *
 * 摩托车主题 - 继承 WeShop 默认主题，提供摩托车/配件电商风格
 */

use Weline\Framework\Register\Register;

Register::register(
    \Weline\Theme\Register\TypeInterface::type,
    'WeShop_Motor',
    [
        'name' => 'weshop-motor',
        'path' => __DIR__,
        'parent' => 'weshop-default'
    ],
    '1.0.0',
    '摩托车主题 - 机械运动风格电商主题'
);
