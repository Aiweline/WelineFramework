<?php

declare(strict_types=1);

namespace Weline\Customer\Extends\Module\Weline_Framework\Query;

use Weline\Customer\Service\CustomerMarketingFactsService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

/**
 * Read-only customer facts for Marketing segments / lifecycle.
 */
final class CustomerSignalsQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'customer_signals';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        /** @var CustomerMarketingFactsService $service */
        $service = ObjectManager::getInstance(CustomerMarketingFactsService::class);

        return match ($operation) {
            'get_customer_facts' => [
                'item' => $service->getFacts((int)($params['customer_id'] ?? 0)),
            ],
            default => throw new \InvalidArgumentException('Unsupported customer_signals operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'customer_signals',
            'name' => (string)\__('客户营销信号'),
            'description' => (string)\__('只读客户联系方式与已购摘要，供营销分群使用。'),
            'module' => 'Weline_Customer',
            'operations' => [
                [
                    'name' => 'get_customer_facts',
                    'description' => (string)\__('获取客户邮箱、注册时间与已购计数'),
                    'frontend' => false,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Customer::customer_index',
                    ],
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'customer_id', 'type' => 'int', 'required' => true],
                    ],
                ],
            ],
        ];
    }
}
