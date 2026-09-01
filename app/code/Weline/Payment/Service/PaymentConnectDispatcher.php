<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Interface\ProviderConnectInterface;
use Weline\Payment\Interface\ProviderInterface;
use Weline\Payment\Model\PaymentMethod;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

/**
 * Routes one-click authorize / connect by method_code to optional ProviderConnectInterface.
 */
final class PaymentConnectDispatcher
{
    public function __construct(
        private readonly PaymentMethodManager $methodManager,
        private readonly ObjectManager $objectManager,
    ) {
    }

    public function resolveConnect(string $methodCode): ProviderConnectInterface
    {
        $methodCode = strtolower(trim($methodCode));
        if ($methodCode === '') {
            throw new \InvalidArgumentException((string) __('支付方式 method_code 不能为空'));
        }

        $method = $this->methodManager->getMethodByCode($methodCode);
        if ($method === null || !$method->getId()) {
            throw new \RuntimeException((string) __('支付方式不存在：%{1}', [$methodCode]));
        }

        $provider = $this->methodManager->getProviderInstance($method);
        if (!$provider instanceof ProviderConnectInterface) {
            throw new \RuntimeException((string) __(
                '支付方式 %{1} 不支持一键授权（未实现 ProviderConnectInterface）。',
                [$methodCode]
            ));
        }

        return $provider;
    }

    /**
     * @param array{website_code?:string,store_code?:string,channel_code?:string,locale?:string} $context
     * @return array{authorization_url:string,state:string,environment:string,method_code:string}
     */
    public function start(string $methodCode, string $environment = 'sandbox', ?string $scope = null, array $context = []): array
    {
        $connect = $this->resolveConnect($methodCode);
        $scope = $this->normalizeStorageScope($scope, $context);
        $started = $connect->startConnect($environment, $scope, $context);

        return array_replace($started, ['method_code' => strtolower(trim($methodCode))]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{result:array<string,mixed>,connect:ProviderConnectInterface,method_code:string}
     */
    public function complete(array $params): array
    {
        $state = trim((string) ($params['state'] ?? ''));
        $methodCode = strtolower(trim((string) ($params['method_code'] ?? '')));
        $connect = null;

        if ($methodCode !== '') {
            $connect = $this->resolveConnect($methodCode);
        } elseif ($state !== '') {
            $connect = $this->findConnectByState($state);
        }

        if ($connect === null) {
            throw new \RuntimeException((string) __('无法识别授权回跳所属支付方式，请重新发起授权。'));
        }

        $result = $connect->completeConnect($params);
        $resolvedCode = $methodCode !== '' ? $methodCode : $this->methodCodeOf($connect);

        return [
            'result' => $result,
            'connect' => $connect,
            'method_code' => $resolvedCode,
        ];
    }

    public function findConnectByState(string $state): ?ProviderConnectInterface
    {
        $state = trim($state);
        if ($state === '') {
            return null;
        }

        foreach ($this->connectCapableProviders() as $provider) {
            if ($provider->ownsOAuthState($state)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @return list<ProviderConnectInterface>
     */
    private function connectCapableProviders(): array
    {
        /** @var PaymentMethod $probe */
        $probe = $this->objectManager->getInstance(PaymentMethod::class, [], false);
        $rows = $probe->select()->fetch();
        if (\is_object($rows) && method_exists($rows, 'getItems')) {
            $rows = $rows->getItems();
        }
        if (!\is_array($rows)) {
            $rows = [];
        }

        $out = [];
        foreach ($rows as $method) {
            if (!$method instanceof PaymentMethod) {
                continue;
            }
            $provider = $this->methodManager->getProviderInstance($method);
            if ($provider instanceof ProviderConnectInterface) {
                $out[] = $provider;
            }
        }

        // Built-in paypal may exist before method row is active; ensure scanner class is tried.
        if ($out === []) {
            $paypal = $this->tryBuiltinPaypalConnect();
            if ($paypal !== null) {
                $out[] = $paypal;
            }
        }

        return $out;
    }

    private function tryBuiltinPaypalConnect(): ?ProviderConnectInterface
    {
        $class = \Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider::class;
        if (!class_exists($class)) {
            return null;
        }
        $provider = $this->objectManager->getInstance($class);
        if ($provider instanceof ProviderConnectInterface) {
            return $provider;
        }

        return null;
    }

    private function methodCodeOf(ProviderConnectInterface $connect): string
    {
        if ($connect instanceof ProviderInterface) {
            return strtolower(trim($connect->getCode()));
        }

        return '';
    }

    /**
     * @param array{website_code?:string,store_code?:string,channel_code?:string} $context
     */
    private function normalizeStorageScope(?string $scope, array $context = []): string
    {
        /** @var SystemConfigTargetScopeService $targetScopeService */
        $targetScopeService = $this->objectManager->getInstance(SystemConfigTargetScopeService::class);
        $websiteCode = strtolower(trim((string) ($context['website_code'] ?? '')));
        $storeCode = strtolower(trim((string) ($context['store_code'] ?? '')));
        $channelCode = strtolower(trim((string) ($context['channel_code'] ?? '')));
        $scope = trim((string) $scope);

        if ($websiteCode !== '' || $storeCode !== '' || $channelCode !== '') {
            $target = $targetScopeService->resolveFromInput([
                'website_code' => $websiteCode,
                'store_code' => $storeCode,
                'channel_code' => $channelCode,
            ], false);
        } elseif ($scope !== '' && substr_count($scope, '.') === 2) {
            $target = $targetScopeService->resolveFromInput(['scope' => $scope], false);
        } else {
            throw new \InvalidArgumentException((string) __(
                '一键授权缺少显式配置范围（target_scope / website_code），已拒绝静默写入 Global。'
            ));
        }

        $resolved = (string) ($target['storage_scope'] ?? '');
        if ($resolved === '' || substr_count($resolved, '.') !== 2) {
            throw new \InvalidArgumentException((string) __('无法解析支付授权的配置写入范围。'));
        }

        return $resolved;
    }
}
