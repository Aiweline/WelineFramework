<?php

declare(strict_types=1);

namespace WeShop\Compare\Controller\Frontend\Compare;

use WeShop\Compare\Service\CompareService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class Add extends FrontendController
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

        $productId = $this->readProductId();
        if ($productId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Product ID is required.'),
            ]);
        }

        $this->compareService->addToCompare($customerId, $productId);
        $compareCount = $this->compareService->getCompareCount($customerId);

        if ($this->shouldReturnJson()) {
            return $this->fetchJson([
                'success' => true,
                'message' => __('Added to compare.'),
                'data' => [
                    'product_id' => $productId,
                    'compare_count' => $compareCount,
                ],
            ]);
        }

        $this->getMessageManager()->addSuccess(__('Added to compare.'));
        $this->redirect('compare');
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

    protected function readProductId(): int
    {
        return (int) (
            $this->request->body('product_id')
            ?? $this->request->getPost('product_id')
            ?? $this->request->getParam('product_id')
            ?? 0
        );
    }
}
