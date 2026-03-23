<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Controller\Frontend\RecentlyViewed;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\RecentlyViewed\Service\RecentlyViewedService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class Remove extends FrontendController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly RecentlyViewedService $recentlyViewedService,
        private readonly Url $url
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            if ($this->shouldReturnJson()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Please log in to continue.'),
                    'data' => [
                        'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
                    ],
                ]);
            }

            $this->getMessageManager()->addError(__('Please log in to continue.'));
            $this->redirect(self::LOGIN_ROUTE);
            return '';
        }

        $viewId = $this->readViewId();
        if ($viewId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Recently viewed item ID is required.'),
            ]);
        }

        $this->recentlyViewedService->removeView($viewId, $customerId);
        $recentlyViewedCount = $this->recentlyViewedService->getRecentlyViewedCount($customerId);

        if ($this->shouldReturnJson()) {
            return $this->fetchJson([
                'success' => true,
                'message' => __('Removed from recently viewed.'),
                'data' => [
                    'view_id' => $viewId,
                    'recently_viewed_count' => $recentlyViewedCount,
                ],
            ]);
        }

        $this->getMessageManager()->addSuccess(__('Removed from recently viewed.'));
        $this->redirect('recently-viewed');
        return '';
    }

    public function post(): string
    {
        return $this->index();
    }

    protected function shouldReturnJson(): bool
    {
        return $this->request->isAjax() || strtoupper((string) $this->request->getMethod()) === 'POST';
    }

    protected function readViewId(): int
    {
        return (int) (
            $this->request->body('view_id')
            ?? $this->request->getPost('view_id')
            ?? $this->request->getParam('view_id')
            ?? $this->request->body('item_id')
            ?? $this->request->getPost('item_id')
            ?? $this->request->getParam('item_id')
            ?? 0
        );
    }
}
