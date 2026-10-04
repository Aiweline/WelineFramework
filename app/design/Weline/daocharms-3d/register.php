<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;
use Weline\Theme\Register\TypeInterface;

Register::register(
    TypeInterface::type,
    'Weline_Daocharms3dTheme',
    [
        'name' => 'daocharms-3d',
        'parent' => 'daocharms',
        'path' => __DIR__,
    ],
    '1.0.0',
    'DaoCharms 三维古镇主题：继承完整店面、搜索、账户与购买流程。'
);
