<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Interface\ProviderInterface;
use Weline\Payment\Model\PaymentMethod;

/**
 * Dispatches Provider controller* methods registered under payment/frontend/provider/{method_code}/{action}.
 */
final class PaymentProviderControllerDispatcher
{
    /** @var list<string> */
    public const FORBIDDEN_ACTIONS = ['return', 'cancel', 'notify'];

    public function __construct(
        private readonly PaymentMethodManager $methodManager,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function dispatch(string $methodCode, string $action, array $params = []): string
    {
        $methodCode = strtolower(trim($methodCode));
        $action = $this->normalizeAction($action);
        if ($methodCode === '' || $action === '') {
            throw new \InvalidArgumentException('payment_provider_controller_route_invalid');
        }
        if (\in_array($action, self::FORBIDDEN_ACTIONS, true)) {
            throw new \InvalidArgumentException('payment_provider_controller_action_forbidden');
        }

        $paymentMethod = $this->methodManager->getMethodByCode($methodCode);
        if (!$paymentMethod instanceof PaymentMethod) {
            throw new \RuntimeException(__('支付方式 %{code} 不存在或未启用', ['code' => $methodCode]));
        }

        $scope = (new PaymentScopeConfigService())->resolveScope($params);
        $provider = $this->methodManager->getProviderInstance($paymentMethod, array_replace($params, $scope));
        if (!$provider instanceof ProviderInterface) {
            throw new \RuntimeException(__('支付 Provider 实例不可用。'));
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
