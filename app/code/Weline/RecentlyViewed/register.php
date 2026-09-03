<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_RecentlyViewed',
    __DIR__,
    '1.0.0',
    '店面最近浏览：Cookie MRU 记录与 recently-viewed 部件（default_injections → product-recently-viewed）',
    ['Weline_Framework'],
);
