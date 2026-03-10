<?php
declare(strict_types=1);

namespace Weline\Websites\Service\AI\Tool;

use Weline\Ai\Interface\ToolInterface;
use Weline\Framework\Service\Query\FrameworkQueryService;

/**
 * 获取域名商账号列表工具
 */
class GetRegistrarAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly FrameworkQueryService $queryService
    ) {
    }

    public function getName(): string
    {
        return 'get_registrar_accounts';
    }

    public function getDescription(): string
    {
        return 'Get list of configured registrar accounts (domain registrars) for domain purchase. Returns account_id, account_name, registrar_name.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by status: active (default)',
                ],
            ],
        ];
    }

    public function execute(array $args): mixed
    {
        $status = $args['status'] ?? 'active';
        return $this->queryService->execute('websites', 'getRegistrarAccounts', [
            'status' => $status,
        ]);
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
