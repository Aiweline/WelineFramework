<?php
declare(strict_types=1);

namespace Weline\Saas\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Websites\Service\ProvisioningQueryHandler;

/**
 * 兼容入口：能力已并入 w_query('websites', …)。
 *
 * @deprecated 请改用 w_query('websites', $operation, $params)
 */
class SaasQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'saas';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        if (!\in_array($operation, ProvisioningQueryHandler::operationNames(), true)) {
            throw new \InvalidArgumentException(
                (string) __('SaaS 查询器已合并至 Websites，请使用 w_query(\'websites\', …)：%{1}', $operation)
            );
        }

        return w_query('websites', $operation, $params);
    }

    public function getDescriptor(): array
    {
        $handler = ObjectManager::getInstance(ProvisioningQueryHandler::class);

        return [
            'provider' => 'saas',
            'name' => __('SaaS 配置编排查询（已合并至 Websites）'),
            'description' => __('等价于 websites 查询器的同名操作'),
            'module' => 'Weline_Saas',
            'operations' => $handler->getDescriptorOperations(),
        ];
    }
}
