<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Blog',
    __DIR__,
    '1.0.0',
    '博客模块：/blog 命名空间主权、结构化文章、CMS 兼容、Search 与 SEO/Sitemap 统一投影',
    ['Weline_Framework', 'Weline_Websites', 'Weline_Theme'],
);
