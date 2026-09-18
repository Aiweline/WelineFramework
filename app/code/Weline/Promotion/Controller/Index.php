<?php

declare(strict_types=1);

namespace Weline\Promotion\Controller;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Promotion\Service\PromotionStorefrontPageService;

class Index extends FrontendController
{
    protected ?string $layoutType = 'promotion.default';

    public function __construct(
        private readonly PromotionStorefrontPageService $pageService,
    ) {
    }

    public function index(): string
    {
        return $this->renderPromotionPage('index');
    }

    protected function renderPromotionPage(string $pageType): string
    {
        if (!$this->pageService->isPageAvailable($pageType)) {
            $this->redirect(404);

            return '';
        }

        $data = $this->pageService->build($pageType);

        $this->request->setData('params', $this->request->getParameterBag()->all());

        $this->forceThemeShell();
        $this->assign($data);
        $this->assign('title', (string)($data['title'] ?? __('活动中心')));
        // SEO ownership: products profile lives only under seo.* (UI page_type stays slug).
        if (isset($data['seo']) && is_array($data['seo'])) {
            $this->assign('seo', $data['seo']);
        }

        return (string)$this->fetch('Weline_Promotion::templates/frontend/promotion/index.phtml');
    }

    private function forceThemeShell(): void
    {
        $meta = $this->getTemplate()->getData('meta');
        if (!is_array($meta)) {
            $meta = [];
        }

        $meta['showHeader'] = true;
        $meta['showFooter'] = true;
        $this->getTemplate()->setData('meta', $meta);
    }
}
