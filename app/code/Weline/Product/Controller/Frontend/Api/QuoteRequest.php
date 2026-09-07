<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend\Api;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ProductQuoteRequestSubmitInterface;

/**
 * Storefront JSON endpoint for Product-owned quote_only submissions.
 */
final class QuoteRequest extends FrontendController
{
    public function post(): string
    {
        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');

        $payload = $this->request->getBodyParams();
        if (!is_array($payload) || $payload === []) {
            $payload = $this->request->getParams();
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        try {
            /** @var ProductQuoteRequestSubmitInterface $service */
            $service = ObjectManager::getInstance(ProductQuoteRequestSubmitInterface::class);
            $result = $service->submit($payload);
            $response->setHttpResponseCode(200);

            return $this->encodeJson(['success' => true] + $result);
        } catch (\InvalidArgumentException $exception) {
            $response->setHttpResponseCode(400);

            return $this->encodeJson([
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        } catch (\Throwable $throwable) {
            $response->setHttpResponseCode(500);

            return $this->encodeJson([
                'success' => false,
                'message' => (string)__('提交失败，请稍后重试。'),
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"success":false}';
    }
}
