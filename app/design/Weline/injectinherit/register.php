<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    \Weline\Theme\Register\TypeInterface::type,
    'Weline_Injectinherit',
    [
        'name' => 'injectinherit',
        'parent' => 'injectprobe',
        'path' => __DIR__,
    ],
    '1.0.0',
    '继承探针：无本层布局覆盖，验收子主题继承父布局后应用部件槽注入仍生效'
);
