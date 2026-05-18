<?php

declare(strict_types=1);

namespace Weline\Cart\Controller;

use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{
    public function index(): string
    {
        $html = w_query('cart', 'renderPage', [], 'frontend');
        if (!\is_string($html)) {
            throw new \RuntimeException((string) __('购物车页面实现必须返回 HTML 字符串。'));
        }

        return $html;
    }
}

