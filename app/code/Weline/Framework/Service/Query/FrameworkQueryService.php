<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Runtime\RequestContext;

class FrameworkQueryService
{
    public function __construct(
        private readonly EventsManager $eventsManager,
        private readonly QueryProviderRegistry $registry
    ) {
    }

    /**
     * @param string|null $provider  提供者标识、模块名（WeShop_Product）、或 framework
     * @param string|null $operation 操作名；为空时进入帮助模式
     * @param array       $params    操作参数
     * @param string      $area      frontend|backend|frontend_worker
     * @return mixed
     */
    public function execute(?string $provider = null, ?string $operation = null, array $params = [], string $area = 'frontend'): mixed
    {
        $provider = $provider ?? '';
        $operation = $operation ?? '';

        if ($operation === '' || $operation === 'help') {
            return $this->introspectHelp($provider, $params, $area, $this->shouldFilterFrontendOnly($params, $area));
        }

        if ($provider === 'framework' && $operation === 'introspect') {
            return $this->introspect($params, $this->shouldFilterFrontendOnly($params, $area));
        }

        if ($provider === '') {
            throw new \InvalidArgumentException((string)__('参数 provider 不能为空'));
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
     * 帮助模式：w_query()、w_query('widget')、w_query('WeShop_Product')
     *
     * @param array<string, mixed> $params
     */
    public function introspectHelp(string $provider, array $params = [], string $area = 'backend', bool $frontendOnly = false): mixed
    {
        $targetOperation = (string)($params['operation'] ?? '');
        if ($targetOperation !== '' && $provider !== '') {
            $resolved = $this->resolveProviderInput($provider);
            if ($resolved['type'] !== 'resolved') {
                throw $this->buildResolveException($resolved, $provider);
            }
            $op = $this->introspectOperation(
                ['provider' => $resolved['provider'], 'operation' => $targetOperation],
                $this->registry->getAllDescriptors()
            );
            if ($frontendOnly) {
                if (($op['frontend'] ?? false) !== true) {
                    throw new \InvalidArgumentException((string)__('该 operation 未暴露给前端：%{1}.%{2}', [$resolved['provider'], $targetOperation]));
                }
                return $this->appendOperationUsage($op, $resolved['provider']);
            }
            return $this->appendOperationUsage($op, $resolved['provider']);
        }

        if ($provider === '' || $provider === 'framework' || $provider === 'help') {
            return $this->introspect(['what' => 'providers'], $frontendOnly);
        }

        $resolved = $this->resolveProviderInput($provider);
        if ($resolved['type'] !== 'resolved') {
            throw $this->buildResolveException($resolved, $provider);
        }

        return $this->introspectProviderDescriptor($resolved['provider'], $frontendOnly);
    }

    /**
     * 使用说明查询（introspect）
     *
     * @param array<string, mixed> $params what=providers|operations|operation|provider|modules
     */
    private function introspect(array $params, bool $frontendOnly = false): mixed
    {
        $what = (string)($params['what'] ?? 'providers');
        $descriptors = $this->registry->getAllDescriptors();
        if ($frontendOnly) {
            $descriptors = $this->filterDescriptorsForFrontend($descriptors);
        }

        return match ($what) {
            'providers' => \array_map(fn(array $d) => [
                'provider' => $d['provider'] ?? '',
                'name' => $d['name'] ?? '',
                'description' => $d['description'] ?? '',
                'module' => $d['module'] ?? '',
                'operation_count' => \count($d['operations'] ?? []),
            ], $descriptors),

            'operations' => $this->introspectOperations($params, $descriptors),

            'operation' => $this->introspectOperation($params, $descriptors),

            'provider' => $this->introspectProviderByParams($params, $descriptors),

            'modules' => $this->introspectModules($descriptors),

            default => throw new \InvalidArgumentException((string)__('introspect 参数 what 必须为 providers、operations、operation、provider 或 modules，当前：%{1}', $what)),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $descriptors
     */
    private function introspectModules(array $descriptors): array
    {
        $grouped = [];
        foreach ($descriptors as $descriptor) {
            $module = (string)($descriptor['module'] ?? '');
            if ($module === '') {
                continue;
            }
            if (!isset($grouped[$module])) {
                $grouped[$module] = [
                    'module' => $module,
                    'providers' => [],
                ];
            }
            $grouped[$module]['providers'][] = [
                'provider' => $descriptor['provider'] ?? '',
                'name' => $descriptor['name'] ?? '',
                'description' => $descriptor['description'] ?? '',
                'operation_count' => \count($descriptor['operations'] ?? []),
            ];
        }

        return \array_values($grouped);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<int, array<string, mixed>> $descriptors
     */
    private function introspectProviderByParams(array $params, array $descriptors): array
    {
        $input = (string)($params['provider'] ?? $params['module'] ?? '');
        if ($input === '') {
            throw new \InvalidArgumentException((string)__('introspect what=provider 时，参数 provider 或 module 必填'));
        }

        $resolved = $this->resolveProviderInput($input);
        if ($resolved['type'] !== 'resolved') {
            throw $this->buildResolveException($resolved, $input);
        }

        foreach ($descriptors as $descriptor) {
            if (($descriptor['provider'] ?? '') === $resolved['provider']) {
                return $this->enrichProviderDescriptor($descriptor);
            }
        }

        throw new \InvalidArgumentException((string)__('未找到 provider：%{1}', $resolved['provider']));
    }

    /**
     * @return array{type: string, provider?: string, candidates?: array<int, array<string, string>>, suggestions?: array<int, string>}
     */
    public function resolveProviderInput(string $input): array
    {
        $normalizedModule = $this->normalizeModuleInput($input);
        $normalizedProvider = \strtolower(\trim($input));
        $descriptors = $this->registry->getAllDescriptors();

        foreach ($descriptors as $descriptor) {
            if (\strtolower((string)($descriptor['provider'] ?? '')) === $normalizedProvider) {
                return ['type' => 'resolved', 'provider' => (string)$descriptor['provider']];
            }
        }

        $moduleMatches = [];
        foreach ($descriptors as $descriptor) {
            $module = (string)($descriptor['module'] ?? '');
            if ($module !== '' && \strtolower($module) === \strtolower($normalizedModule)) {
                $moduleMatches[] = $descriptor;
            }
        }
        if (\count($moduleMatches) === 1) {
            return ['type' => 'resolved', 'provider' => (string)($moduleMatches[0]['provider'] ?? '')];
        }
        if (\count($moduleMatches) > 1) {
            return [
                'type' => 'ambiguous',
                'candidates' => \array_map(static fn(array $d) => [
                    'provider' => (string)($d['provider'] ?? ''),
                    'module' => (string)($d['module'] ?? ''),
                    'name' => (string)($d['name'] ?? ''),
                ], $moduleMatches),
            ];
        }

        $suffixMatches = [];
        foreach ($descriptors as $descriptor) {
            $providerName = (string)($descriptor['provider'] ?? '');
            if ($providerName !== '' && $providerName === $normalizedProvider) {
                $suffixMatches[] = $descriptor;
            }
        }
        if (\count($suffixMatches) === 1) {
            return ['type' => 'resolved', 'provider' => (string)($suffixMatches[0]['provider'] ?? '')];
        }

        return [
            'type' => 'not_found',
            'suggestions' => $this->suggestProviders($input, $descriptors),
        ];
    }

    private function normalizeModuleInput(string $input): string
    {
        $input = \trim($input);
        if ($input === '') {
            return '';
        }
        $input = \str_replace('/', '_', $input);
        if (\str_contains($input, '_')) {
            [$vendor, $module] = \explode('_', $input, 2);
            return $vendor . '_' . $module;
        }

        return $input;
    }

    /**
     * @param array{type: string, provider?: string, candidates?: array<int, array<string, string>>, suggestions?: array<int, string>} $resolved
     */
    private function buildResolveException(array $resolved, string $input): \InvalidArgumentException
    {
        if ($resolved['type'] === 'ambiguous') {
            $lines = [];
            foreach (($resolved['candidates'] ?? []) as $candidate) {
                $lines[] = \sprintf('%s (%s)', $candidate['provider'] ?? '', $candidate['module'] ?? '');
            }
            return new \InvalidArgumentException((string)__(
                '模块 %{1} 对应多个 provider，请指定其一：%{2}',
                [$input, \implode(', ', $lines)]
            ));
        }

        $suggestions = \implode(', ', $resolved['suggestions'] ?? []);
        $message = $suggestions !== ''
            ? (string)__('未找到 provider 或模块：%{1}。相似项：%{2}', [$input, $suggestions])
            : (string)__('未找到 provider 或模块：%{1}', $input);

        return new \InvalidArgumentException($message);
    }

    /**
     * @param array<int, array<string, mixed>> $descriptors
     * @return array<int, string>
     */
    private function suggestProviders(string $input, array $descriptors, int $limit = 5): array
    {
        $needle = \strtolower($input);
        $scored = [];
        foreach ($descriptors as $descriptor) {
            $provider = (string)($descriptor['provider'] ?? '');
            $module = (string)($descriptor['module'] ?? '');
            $haystacks = [$provider, $module, \strtolower($provider), \strtolower($module)];
            $score = 0;
            foreach ($haystacks as $haystack) {
                if ($haystack === '') {
                    continue;
                }
                if ($haystack === $needle) {
                    $score += 100;
                } elseif (\str_contains(\strtolower($haystack), $needle) || \str_contains($needle, \strtolower($haystack))) {
                    $score += 10;
                }
            }
            if ($score > 0 && $provider !== '') {
                $scored[$provider] = $score;
            }
        }
        \arsort($scored);

        return \array_slice(\array_keys($scored), 0, $limit);
    }

    private function introspectProviderDescriptor(string $providerName, bool $frontendOnly = false): array
    {
        foreach ($this->registry->getAllDescriptors() as $descriptor) {
            if (($descriptor['provider'] ?? '') !== $providerName) {
                continue;
            }
            if ($frontendOnly) {
                $descriptor = $this->filterDescriptorForFrontend($descriptor);
                if ($descriptor === null) {
                    throw new \InvalidArgumentException((string)__('该 provider 没有暴露给前端的 operation：%{1}', $providerName));
                }
            }
            return $this->enrichProviderDescriptor($descriptor);
        }

        throw new \InvalidArgumentException((string)__('未找到 provider：%{1}', $providerName));
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private function enrichProviderDescriptor(array $descriptor): array
    {
        $provider = (string)($descriptor['provider'] ?? '');
        $operations = [];
        foreach (($descriptor['operations'] ?? []) as $operation) {
            if (!\is_array($operation)) {
                continue;
            }
            $operations[] = $this->appendOperationUsage($operation, $provider);
        }
        $descriptor['operations'] = $operations;
        $descriptor['usage'] = [
            "w_query('{$provider}')",
            "php bin/w query:help {$provider}",
        ];
        if (($descriptor['module'] ?? '') !== '') {
            $descriptor['usage'][] = "php bin/w query:help " . $descriptor['module'];
        }

        return $descriptor;
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function appendOperationUsage(array $operation, string $provider): array
    {
        $name = (string)($operation['name'] ?? '');
        if ($name !== '') {
            $operation['example'] = "w_query('{$provider}', '{$name}', [...])";
            $operation['cli_example'] = "php bin/w query:help {$provider} {$name}";
        }

        return $operation;
    }

    /**
     * @param array<int, array<string, mixed>> $descriptors
     * @return array<int, array<string, mixed>>
     */
    private function filterDescriptorsForFrontend(array $descriptors): array
    {
        $filtered = [];
        foreach ($descriptors as $descriptor) {
            $next = $this->filterDescriptorForFrontend($descriptor);
            if ($next !== null) {
                $filtered[] = $next;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>|null
     */
    private function filterDescriptorForFrontend(array $descriptor): ?array
    {
        $operations = [];
        foreach (($descriptor['operations'] ?? []) as $operation) {
            if (!\is_array($operation)) {
                continue;
            }
            if (($operation['frontend'] ?? false) === true) {
                $operations[] = $operation;
            }
        }
        if ($operations === []) {
            return null;
        }
        $descriptor['operations'] = $operations;
        $descriptor['operation_count'] = \count($operations);

        return $descriptor;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function shouldFilterFrontendOnly(array $params, string $area): bool
    {
        if (($params['frontend_only'] ?? false) === true) {
            return true;
        }

        if (($params['frontend_only'] ?? null) === false) {
            return false;
        }

        return $area === 'frontend_worker';
    }

    /**
     * @param array<int, array<string, mixed>> $descriptors
     */
    private function introspectOperations(array $params, array $descriptors): array
    {
        $targetProvider = (string)($params['provider'] ?? '');
        if ($targetProvider === '') {
            throw new \InvalidArgumentException((string)__('introspect what=operations 时，参数 provider 必填'));
        }
        $resolved = $this->resolveProviderInput($targetProvider);
        if ($resolved['type'] !== 'resolved') {
            throw $this->buildResolveException($resolved, $targetProvider);
        }
        foreach ($descriptors as $d) {
            if (($d['provider'] ?? '') === $resolved['provider']) {
                return $d['operations'] ?? [];
            }
        }
        throw new \InvalidArgumentException((string)__('未找到 provider：%{1}', $resolved['provider']));
    }

    /**
     * @param array<int, array<string, mixed>> $descriptors
     * @return array<string, mixed>
     */
    private function introspectOperation(array $params, array $descriptors): array
    {
        $targetProvider = (string)($params['provider'] ?? '');
        $targetOperation = (string)($params['operation'] ?? '');
        if ($targetProvider === '' || $targetOperation === '') {
            throw new \InvalidArgumentException((string)__('introspect what=operation 时，参数 provider 和 operation 必填'));
        }
        $resolved = $this->resolveProviderInput($targetProvider);
        if ($resolved['type'] !== 'resolved') {
            throw $this->buildResolveException($resolved, $targetProvider);
        }
        foreach ($descriptors as $d) {
            if (($d['provider'] ?? '') !== $resolved['provider']) {
                continue;
            }
            foreach (($d['operations'] ?? []) as $op) {
                if (($op['name'] ?? '') === $targetOperation) {
                    return \is_array($op) ? $op : [];
                }
            }
            throw new \InvalidArgumentException((string)__('provider %{1} 中未找到 operation：%{2}', $resolved['provider'], $targetOperation));
        }
        throw new \InvalidArgumentException((string)__('未找到 provider：%{1}', $resolved['provider']));
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
