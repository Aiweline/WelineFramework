<?php

declare(strict_types=1);

namespace Weline\Wishlist\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Wishlist\Service\WishlistService;

/**
 * @Cdn cache=false description="心愿单列表页不边缘缓存"
 */
final class Index extends FrontendController
{
    public function __construct(
        private readonly WishlistService $wishlist,
    ) {
    }

    public function index(): string
    {
        $payload = $this->wishlist->listPage();
        $this->layoutType = 'default';
        $this->request->setGet('page_type', 'wishlist');
        $this->request->setGet('theme_public_route', 'wishlist');
        $this->request->setGet('theme_page_title', (string)__('我的收藏'));
        $this->assign('page_title', (string)__('我的收藏'));
        $this->assign('wishlist_items', $payload['items'] ?? []);
        $this->assign('wishlist_count', (int)($payload['wishlist_count'] ?? 0));

        return (string)$this->fetch('Weline_Wishlist::templates/frontend/wishlist/index.phtml');
    }
}
