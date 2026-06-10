<?php

declare(strict_types=1);

namespace Aiweline\A2A\Controller\Frontend;

use Aiweline\A2A\Service\PurchaseIntentService;
use Weline\Framework\App\Controller\FrontendController;

class Purchase extends FrontendController
{
    public function __construct(
        private readonly PurchaseIntentService $purchaseIntentService
    ) {
    }

    public function index(): string
    {
        $this->disablePurchasePageCache();

        $skuCode = (string) ($this->request->getParam('sku') ?? '');

        try {
            $intent = $this->purchaseIntentService->createDraft($skuCode);
            foreach ($intent as $key => $value) {
                $this->assign($key, $value);
            }
            $this->assign('has_error', false);
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setHttpResponseCode(404);
            $this->assign('page_title', __('A2A 托管订单草稿'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        }

        return $this->getTemplate()->fetch('Aiweline_A2A::templates/Frontend/Purchase/index.phtml');
    }

    private function disablePurchasePageCache(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');
    }
}
