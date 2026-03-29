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
        $result = $this->queryService->execute('websites', 'getRegistrarAccounts', [
            'status' => $status,
        ]);

        if (\is_array($result) && $result === []) {
            return $this->getSimulatedAccounts();
        }

        return $result;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * @return list<array{account_id:int,account_name:string,registrar_name:string,registrar_code:string,simulated:bool}>
     */
    private function getSimulatedAccounts(): array
    {
        return [
            [
                'account_id' => 900001,
                'account_name' => '本地演示主账号',
                'registrar_name' => '本地演示服务商',
                'registrar_code' => 'local_demo',
                'simulated' => true,
            ],
            [
                'account_id' => 900002,
                'account_name' => '本地演示备用账号',
                'registrar_name' => '沙盒域名',
                'registrar_code' => 'sandbox_demo',
                'simulated' => true,
            ],
        ];
    }
}
