<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Faq',
    __DIR__,
    '1.0.0',
    '万能 FAQ：/faq 命名空间、Hub、实体问答、CMS PageKind=faq、搜索与 SEO',
    ['Weline_Framework', 'Weline_Websites', 'Weline_Theme', 'Weline_Cms'],
);
