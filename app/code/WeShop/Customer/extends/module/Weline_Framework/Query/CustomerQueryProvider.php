<?php

declare(strict_types=1);

namespace WeShop\Customer\Extends\Module\Weline_Framework\Query;

use WeShop\Customer\Model\Customer;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class CustomerQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly Customer $customerModel
    ) {
    }

    public function getProviderName(): string
    {
        return 'customer';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getCustomersInfo' => $this->getCustomersInfo($params),
            default => throw new \InvalidArgumentException(
                (string) __('Customer 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getCustomersInfo(array $params): array
    {
        $ids = $params['customer_ids'] ?? [];
        if (!\is_array($ids) || $ids === []) {
            return [];
        }

        $ids = \array_values(\array_unique(\array_filter(\array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $customer = clone $this->customerModel;
        $rows = $customer
            ->clear()
            ->where(Customer::schema_fields_ID, $ids, 'in')
            ->select()
            ->fetchArray();

        $result = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $customerId = (int) ($row[Customer::schema_fields_ID] ?? 0);
            if ($customerId <= 0) {
                continue;
            }
            $firstName = (string) ($row[Customer::schema_fields_FIRST_NAME] ?? '');
            $lastName = (string) ($row[Customer::schema_fields_LAST_NAME] ?? '');
            $result[] = [
                'customer_id' => $customerId,
                'email' => (string) ($row[Customer::schema_fields_EMAIL] ?? ''),
                'name' => \trim($firstName . ' ' . $lastName),
            ];
        }

        return $result;
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'customer',
            'name' => __('客户查询'),
            'description' => __('提供客户基础信息查询能力'),
            'module' => 'WeShop_Customer',
            'operations' => [
                [
                    'name' => 'getCustomersInfo',
                    'description' => __('批量获取客户基础信息'),
                    'params' => [
                        ['name' => 'customer_ids', 'type' => 'array', 'required' => true],
                    ],
                ],
            ],
        ];
    }
}
