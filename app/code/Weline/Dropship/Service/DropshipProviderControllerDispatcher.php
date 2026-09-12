<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Interface\DropshipProviderInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Dispatches Provider controller* methods under
 * dropship/{frontend|backend}/provider-gateway/dispatch?provider_code=&action=
 */
final class DropshipProviderControllerDispatcher
{
    /** @var list<string> */
    public const FORBIDDEN_ACTIONS = ['notify', 'callback', 'webhook'];

    public function __construct(
        private readonly DropshipChannelManager $channels,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function dispatch(string $providerCode, string $action, array $params = []): string
    {
        $providerCode = strtolower(trim($providerCode));
        $action = $this->normalizeAction($action);
        if ($providerCode === '' || $action === '') {
            throw new \InvalidArgumentException('dropship_provider_controller_route_invalid');
        }
        if (\in_array($action, self::FORBIDDEN_ACTIONS, true)) {
            throw new \InvalidArgumentException('dropship_provider_controller_action_forbidden');
        }

        $this->channels->registerAllProviders();
        $provider = $this->channels->getProvider($providerCode);
        if (!$provider instanceof DropshipProviderInterface) {
            throw new \RuntimeException(__('货源供应商 %{code} 不存在或未注册', ['code' => $providerCode]));
        }

        $methodName = 'controller' . str_replace(' ', '', ucwords(str_replace('-', ' ', $action)));
        if (!method_exists($provider, $methodName)) {
            throw new \RuntimeException(__('Provider 未实现 %{action} 扩展入口。', ['action' => $action]));
        }

        $result = $provider->{$methodName}($params);
        if (!\is_string($result)) {
            return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        return $result;
    }

    private function normalizeAction(string $action): string
    {
        return strtolower(trim(str_replace('_', '-', $action)));
    }
}
