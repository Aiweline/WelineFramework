<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Frontend;

use Weline\Dropship\Service\DropshipProviderControllerDispatcher;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * Thin shell dispatch for Provider controller* extension routes.
 * URL: dropship/frontend/provider-gateway/dispatch?provider_code={code}&action={kebab}
 */
final class ProviderGateway extends FrontendController
{
    public function dispatch(): string
    {
        $providerCode = strtolower(trim((string) $this->request->getParam('provider_code', '')));
        $action = strtolower(trim((string) $this->request->getParam('action', '')));
        $params = $this->request->getParams();
        if (!\is_array($params)) {
            $params = [];
        }

        try {
            /** @var DropshipProviderControllerDispatcher $dispatcher */
            $dispatcher = ObjectManager::getInstance(DropshipProviderControllerDispatcher::class);
            $body = $dispatcher->dispatch($providerCode, $action, $params);
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
