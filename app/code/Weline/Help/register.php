<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Help',
    __DIR__,
    '1.0.0',
    '帮助中心：/help 命名空间、Hub FAQ、CMS PageKind=help、自有 SEO',
    ['Weline_Framework', 'Weline_Websites', 'Weline_Theme', 'Weline_Cms'],
);
