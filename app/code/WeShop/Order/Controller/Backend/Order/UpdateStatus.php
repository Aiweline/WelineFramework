<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Backend\Order;

use WeShop\Order\Service\OrderService;
use Weline\Admin\Controller\BaseController;

class UpdateStatus extends BaseController
{
    private const DEFAULT_BACK_ROUTE = '*/backend/order';

    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    public function post(): string
    {
        $orderId = (int) $this->request->getParam('id', 0);
        $status = (string) $this->request->getParam('status', '');
        $backUrl = $this->resolveBackUrl(
            (string) $this->request->getParam('back_url', ''),
            $this->getUrl(self::DEFAULT_BACK_ROUTE)
        );

        if (!$orderId) {
            $this->getMessageManager()->addError(__('Order ID is required.'));
            $this->redirect($backUrl);
            return '';
        }

        try {
            $this->orderService->updateOrderStatus($orderId, $status);
            $this->getMessageManager()->addSuccess(__('Order status updated.'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Order status update failed.'));
        }

        $this->redirect($backUrl);
        return '';
    }

    public function index(): string
    {
        return $this->post();
    }

    private function resolveBackUrl(string $backUrl, string $fallback): string
    {
        $backUrl = trim($backUrl);
        if ($backUrl === '') {
            return $fallback;
        }

        // Avoid redirecting to external origins via injected absolute URLs.
        if (str_contains($backUrl, '://') || str_starts_with($backUrl, '//')) {
            return $fallback;
        }

        return $backUrl;
    }
}
