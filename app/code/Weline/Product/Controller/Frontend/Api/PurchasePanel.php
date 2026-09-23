<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend\Api;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\PurchasePanelService;

/**
 * Thin REST shell for listing quick-add panel.
 * Storefront browsers must use BinQuery {@code product.getPurchasePanel} via Weline.Api.
 */
class PurchasePanel extends FrontendController
{
    public function index(): string
    {
        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');

        /** @var PurchasePanelService $service */
        $service = ObjectManager::getInstance(PurchasePanelService::class);
        $payload = $service->render([
            'product_id' => (int)$this->request->getParam('product_id', 0),
            'slug' => (string)$this->request->getParam('slug', ''),
            'offer' => (string)$this->request->getParam('offer', ''),
        ] + (array)$this->request->getParams());

        $status = max(200, (int)($payload['http_status'] ?? 200));
        if ($status !== 200) {
            $response->setHttpResponseCode($status);
        }
        unset($payload['http_status']);

        $json = \json_encode($payload, \JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}
