<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Backend;

use Weline\Dropship\Service\DropshipProviderControllerDispatcher;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * Thin shell dispatch for Provider controller* extension routes (admin).
 * URL: dropship/backend/provider-gateway/dispatch?provider_code={code}&action={kebab}
 */
#[Acl('Weline_Dropship::commerce:dropship:provider_gateway', '货源 Provider 扩展入口', 'ti-link', '调度 Provider controller* 方法', 'Weline_Dropship::commerce:dropship:group')]
final class ProviderGateway extends BackendController
{
    #[Acl('Weline_Dropship::commerce:dropship:provider_gateway_dispatch', '调度 Provider 扩展', 'ti-link', '按 provider_code+action 调度')]
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
