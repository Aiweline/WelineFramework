<?php

declare(strict_types=1);

namespace WeShop\Compare\Controller\Frontend\Compare;

use WeShop\Compare\Service\CompareService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class Remove extends FrontendController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly CompareService $compareService,
        private readonly Url $url
    ) {
    }

    public function index(): string
    {
        if (!$this->isPostRequest()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Invalid request method.'),
            ]);
        }

        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            if (!$this->shouldReturnJson()) {
                $this->getMessageManager()->addError(__('Please log in to continue.'));
                $this->redirect(self::LOGIN_ROUTE);
                return '';
            }

            return $this->fetchJson([
                'success' => false,
                'message' => __('Please log in to continue.'),
                'data' => [
                    'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
                ],
            ]);
        }

        $compareId = $this->readCompareId();
        if ($compareId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Compare item ID is required.'),
            ]);
        }

        $removed = $this->compareService->removeFromCompare($compareId, $customerId);
        if (!$removed) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Compare item could not be removed.'),
            ]);
        }

        return $this->fetchJson([
            'success' => true,
            'message' => __('Removed from compare.'),
            'data' => [
                'compare_count' => $this->compareService->getCompareCount($customerId),
            ],
        ]);
    }

    public function post(): string
    {
        return $this->index();
    }

    protected function shouldReturnJson(): bool
    {
        return $this->request->isAjax();
    }

    protected function isPostRequest(): bool
    {
        return strtoupper((string) $this->request->getMethod()) === 'POST';
    }

    protected function readCompareId(): int
    {
        return (int) (
            $this->request->body('compare_id')
            ?? $this->request->body('item_id')
            ?? $this->request->getPost('compare_id')
            ?? $this->request->getPost('item_id')
            ?? $this->request->getParam('compare_id')
            ?? $this->request->getParam('item_id')
            ?? 0
        );
    }
}
