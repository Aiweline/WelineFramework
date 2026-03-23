<?php

declare(strict_types=1);

namespace WeShop\Promotion\Controller\Frontend\Promotion;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Promotion\Service\PromotionPageDataService;

class Index extends BaseController
{
    protected ?string $layoutType = 'promotion';

    public function __construct(
        private readonly PromotionPageDataService $promotionPageDataService
    ) {
    }

    public function index(): string
    {
        return $this->renderPage('index', __('Promotion Hub'));
    }

    public function deals(): string
    {
        return $this->renderPage('deals', __('Today Deals'));
    }

    public function sale(): string
    {
        return $this->renderPage('sale', __('Seasonal Sale'));
    }

    protected function renderPage(string $pageType, string $title): string
    {
        $page = max(1, (int) ($this->request->getParam('page') ?? 1));
        foreach ($this->promotionPageDataService->build($pageType, $page, 24) as $key => $value) {
            $this->assign($key, $value);
        }

        $this->assign('title', $title);
        return $this->fetch();
    }
}
