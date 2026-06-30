<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

/**
 * 浏览器与统一帮助 QueryProvider
 *
 * 仅暴露 frontend=true 的 operations 子集；完整契约请用 PHP w_query 或 query:help CLI。
 */
class QueryHelpProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'query_help';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        /** @var FrameworkQueryService $queryService */
        $queryService = ObjectManager::getInstance(FrameworkQueryService::class);
        $params['frontend_only'] = true;

        return match ($operation) {
            'providers' => $queryService->introspectHelp('', $params, 'frontend_worker', true),
            'provider' => $this->executeProviderHelp($queryService, $params),
            'operation' => $this->executeOperationHelp($queryService, $params),
            default => throw new \InvalidArgumentException(
                (string)__('query_help 不支持的操作：%{1}', $operation)
            ),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function executeProviderHelp(FrameworkQueryService $queryService, array $params): mixed
    {
        $provider = (string)($params['provider'] ?? $params['module'] ?? '');
        if ($provider === '') {
            throw new \InvalidArgumentException((string)__('query_help provider 操作需要参数 provider 或 module'));
        }

        return $queryService->introspectHelp($provider, $params, 'frontend_worker', true);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function executeOperationHelp(FrameworkQueryService $queryService, array $params): mixed
    {
        $provider = (string)($params['provider'] ?? '');
        $targetOperation = (string)($params['operation'] ?? '');
        if ($provider === '' || $targetOperation === '') {
            throw new \InvalidArgumentException((string)__('query_help operation 操作需要参数 provider 与 operation'));
        }

        return $queryService->introspectHelp($provider, ['operation' => $targetOperation, 'frontend_only' => true], 'frontend_worker', true);
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'query_help',
            'name' => 'Query 帮助',
            'description' => '列出浏览器可访问的 QueryProvider 与 operations（frontend 子集）',
            'module' => 'Weline_Framework',
            'operations' => [
                [
                    'name' => 'providers',
                    'description' => '列出至少有一个 frontend operation 的 provider 摘要',
                    'mode' => 'read',
                    'frontend' => true,
                    'graph' => false,
                    'params' => [],
                ],
                [
                    'name' => 'provider',
                    'description' => '查看单个 provider 的 frontend operations',
                    'mode' => 'read',
                    'frontend' => true,
                    'graph' => false,
                    'params' => [
                        ['name' => 'provider', 'type' => 'string', 'required' => false, 'description' => 'provider 标识'],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => '模块名，如 WeShop_Product'],
                    ],
                ],
                [
                    'name' => 'operation',
                    'description' => '查看单个 frontend operation 详情',
                    'mode' => 'read',
                    'frontend' => true,
                    'graph' => false,
                    'params' => [
                        ['name' => 'provider', 'type' => 'string', 'required' => true, 'description' => 'provider 标识或模块名'],
                        ['name' => 'operation', 'type' => 'string', 'required' => true, 'description' => 'operation 名称'],
                    ],
                ],
            ],
        ];
    }
}
