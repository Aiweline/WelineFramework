<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

class FrameworkQueryService
{
    public function __construct(
        private readonly EventsManager $eventsManager,
        private readonly QueryProviderRegistry $registry
    ) {
    }

    /**
     * @param string $provider  提供者标识（如 crud、widget）或 framework（introspect）
     * @param string $operation 操作名
     * @param array  $params    操作参数
     * @param string $area      frontend|backend
     * @return mixed
     */
    public function execute(string $provider, string $operation, array $params = [], string $area = 'frontend'): mixed
    {
        if ($provider === '') {
            throw new \InvalidArgumentException((string)__('参数 provider 不能为空'));
        }
        if ($operation === '') {
            throw new \InvalidArgumentException((string)__('参数 operation 不能为空'));
        }

        if ($provider === 'framework' && $operation === 'introspect') {
            return $this->introspect($params);
        }

        $eventData = [
            'provider' => $provider,
            'operation' => $operation,
            'params' => $params,
            'area' => $area,
            'allow' => true,
            'error' => '',
            'result' => null,
        ];
        $stepStart = \microtime(true);
        $this->eventsManager->dispatch('Weline_Framework_Query::before_execute', $eventData);
        $this->recordQueryServiceStep('before_execute_event', $stepStart, [
            'provider' => $provider,
            'operation' => $operation,
            'area' => $area,
        ]);

        if (($eventData['allow'] ?? true) !== true) {
            $error = (string)($eventData['error'] ?? __('查询被拒绝'));
            throw new \RuntimeException($error);
        }
        if (\array_key_exists('result', $eventData) && $eventData['result'] !== null) {
            return $eventData['result'];
        }

        $stepStart = \microtime(true);
        $providerInstance = $this->registry->getProvider($provider);
        $this->recordQueryServiceStep('registry_get_provider', $stepStart);
        if ($providerInstance === null) {
            throw new \InvalidArgumentException((string)__('未注册的查询器：%{1}。请通过 extends 注册 QueryProviderInterface 实现。', $provider));
        }

        $stepStart = \microtime(true);
        $result = $providerInstance->execute($operation, (array)$eventData['params']);
        $this->recordQueryServiceStep('provider_execute', $stepStart, [
            'provider_class' => \get_class($providerInstance),
        ]);

        $afterEventData = [
            'provider' => $provider,
            'operation' => $operation,
            'params' => (array)$eventData['params'],
            'area' => $area,
            'result' => $result,
        ];
        $stepStart = \microtime(true);
        $this->eventsManager->dispatch('Weline_Framework_Query::after_execute', $afterEventData);
        $this->recordQueryServiceStep('after_execute_event', $stepStart);
        return $afterEventData['result'] ?? $result;
    }

    /**
     * 使用说明查询（introspect）
     *
     * @param array $params what=providers|operations|operation, provider?, operation?
     */
    private function introspect(array $params): mixed
    {
        $what = (string)($params['what'] ?? 'providers');
        $descriptors = $this->registry->getAllDescriptors();

        return match ($what) {
            'providers' => array_map(fn(array $d) => [
                'provider' => $d['provider'] ?? '',
                'name' => $d['name'] ?? '',
                'description' => $d['description'] ?? '',
                'module' => $d['module'] ?? '',
                'operation_count' => count($d['operations'] ?? []),
            ], $descriptors),

            'operations' => $this->introspectOperations($params, $descriptors),

            'operation' => $this->introspectOperation($params, $descriptors),

            default => throw new \InvalidArgumentException((string)__('introspect 参数 what 必须为 providers、operations 或 operation，当前：%{1}', $what)),
        };
    }

    private function introspectOperations(array $params, array $descriptors): array
    {
        $targetProvider = (string)($params['provider'] ?? '');
        if ($targetProvider === '') {
            throw new \InvalidArgumentException((string)__('introspect what=operations 时，参数 provider 必填'));
        }
        foreach ($descriptors as $d) {
            if (($d['provider'] ?? '') === $targetProvider) {
                return $d['operations'] ?? [];
            }
        }
        throw new \InvalidArgumentException((string)__('未找到 provider：%{1}', $targetProvider));
    }

    private function introspectOperation(array $params, array $descriptors): array
    {
        $targetProvider = (string)($params['provider'] ?? '');
        $targetOperation = (string)($params['operation'] ?? '');
        if ($targetProvider === '' || $targetOperation === '') {
            throw new \InvalidArgumentException((string)__('introspect what=operation 时，参数 provider 和 operation 必填'));
        }
        foreach ($descriptors as $d) {
            if (($d['provider'] ?? '') !== $targetProvider) {
                continue;
            }
            foreach (($d['operations'] ?? []) as $op) {
                if (($op['name'] ?? '') === $targetOperation) {
                    return $op;
                }
            }
            throw new \InvalidArgumentException((string)__('provider %{1} 中未找到 operation：%{2}', $targetProvider, $targetOperation));
        }
        throw new \InvalidArgumentException((string)__('未找到 provider：%{1}', $targetProvider));
    }
    /**
     * @param array<string, mixed> $meta
     */
    private function recordQueryServiceStep(string $name, float $startedAt, array $meta = []): void
    {
        $profile = RequestContext::get('query_bin.service_profile');
        if (!\is_array($profile)) {
            $profile = [];
        }

        $step = [
            'name' => $name,
            'duration_ms' => \round((\microtime(true) - $startedAt) * 1000, 2),
        ];
        if ($meta !== []) {
            $step['meta'] = $meta;
        }
        $profile[] = $step;
        RequestContext::set('query_bin.service_profile', $profile);
    }
}

