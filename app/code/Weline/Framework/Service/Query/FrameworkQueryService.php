<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

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
        $this->eventsManager->dispatch('Weline_Framework_Query::before_execute', $eventData);

        if (($eventData['allow'] ?? true) !== true) {
            $error = (string)($eventData['error'] ?? __('查询被拒绝'));
            throw new \RuntimeException($error);
        }
        if (\array_key_exists('result', $eventData) && $eventData['result'] !== null) {
            return $eventData['result'];
        }

        $providerInstance = $this->registry->getProvider($provider);
        if ($providerInstance === null) {
            throw new \InvalidArgumentException((string)__('未注册的查询器：%{1}。请通过 extends 注册 QueryProviderInterface 实现。', $provider));
        }

        $result = $providerInstance->execute($operation, (array)$eventData['params']);

        $afterEventData = [
            'provider' => $provider,
            'operation' => $operation,
            'params' => (array)$eventData['params'],
            'area' => $area,
            'result' => $result,
        ];
        $this->eventsManager->dispatch('Weline_Framework_Query::after_execute', $afterEventData);
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
}

