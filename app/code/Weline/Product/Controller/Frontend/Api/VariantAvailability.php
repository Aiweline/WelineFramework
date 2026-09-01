<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend\Api;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\StorefrontVariantAvailabilityService;

/**
 * Live variant stock/sellability for CDN-cached product detail pages.
 */
class VariantAvailability extends FrontendController
{
    public function index(): string
    {
        $slug = \strtolower(\trim((string)$this->request->getParam('slug', '')));
        $productId = (int)$this->request->getParam('product_id', 0);

        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');

        $service = ObjectManager::getInstance(StorefrontVariantAvailabilityService::class);

        try {
            $payload = $service->livePayload($slug, $productId);
            if ($payload === null) {
                $response->setHttpResponseCode(404);

                return $this->encodeJson([
                    'success' => false,
                    'message' => (string)__('商品未找到'),
                    'offers' => [],
                ]);
            }

            return $this->encodeJson(['success' => true] + $payload);
        } catch (\Throwable $throwable) {
            $response->setHttpResponseCode(500);

            return $this->encodeJson([
                'success' => false,
                'message' => $throwable->getMessage(),
                'offers' => [],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeJson(array $data): string
    {
        $json = \json_encode($data, \JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}
