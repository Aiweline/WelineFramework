<?php

declare(strict_types=1);

namespace Weline\Payment\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentProviderControllerDispatcher;

/**
 * Thin shell dispatch for Provider controller* extension routes.
 */
final class ProviderGateway extends FrontendController
{
    public function dispatch(): string
    {
        $methodCode = strtolower(trim((string) $this->request->getParam('method_code', '')));
        $action = strtolower(trim((string) $this->request->getParam('action', '')));
        $params = $this->request->getParams();
        if (!\is_array($params)) {
            $params = [];
        }

        try {
            /** @var PaymentProviderControllerDispatcher $dispatcher */
            $dispatcher = ObjectManager::getInstance(PaymentProviderControllerDispatcher::class);
            $body = $dispatcher->dispatch($methodCode, $action, $params);
            $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

            return $body;
        } catch (\Throwable $throwable) {
            $this->request->getResponse()->setHttpResponseCode(400);

            return json_encode([
                'success' => false,
                'message' => $throwable->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"success":false}';
        }
    }
}
