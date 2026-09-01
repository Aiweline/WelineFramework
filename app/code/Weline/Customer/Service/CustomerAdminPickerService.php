<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Model\Customer;
use Weline\Framework\Manager\ObjectManager;

/**
 * 后台客户选品：按姓名 / 邮箱搜索 Customer 账户（customer_id）。
 */
final class CustomerAdminPickerService
{
    public function __construct(
        private readonly Customer $customerPrototype,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function search(string $keyword, int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = max(1, min(50, $limit));
        $keyword = trim($keyword);

        /** @var Customer $query */
        $query = clone $this->customerPrototype;
        $query->reset()->order(Customer::schema_fields_ID, 'DESC');

        if ($keyword !== '') {
            if (ctype_digit($keyword)) {
                $query->where(Customer::schema_fields_ID, (int) $keyword);
            } else {
                $query->search(
                    $keyword,
                    Customer::schema_fields_username . ',' . Customer::schema_fields_email,
                    'or',
                );
            }
        }

        $collection = $query->pagination($page, $limit);
        $rows = $collection->select()->fetch()->getItems();
        $items = [];
        foreach ($rows as $row) {
            $data = is_object($row) && method_exists($row, 'getData') ? $row->getData() : (array) $row;
            $mapped = $this->mapRow($data);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        return [
            'items' => $items,
            'total' => (int) $collection->getTotal(),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }

        /** @var Customer $customer */
        $customer = clone $this->customerPrototype;
        $customer->clear()->load($customerId);
        if (!$customer->getId()) {
            return null;
        }

        return $this->mapRow($customer->getData());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function mapRow(array $data): ?array
    {
        $customerId = (int) ($data[Customer::schema_fields_ID] ?? $data['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return null;
        }

        $email = strtolower(trim((string) ($data[Customer::schema_fields_email] ?? '')));
        $username = trim((string) ($data[Customer::schema_fields_username] ?? ''));
        $label = $username !== '' ? $username : ($email !== '' ? $email : '#' . $customerId);
        $meta = $email !== '' && strcasecmp($email, $label) !== 0 ? $email : '';

        return [
            'value' => (string) $customerId,
            'label' => $label,
            'meta' => $meta,
            'customer_id' => $customerId,
            'username' => $username,
            'email' => $email,
        ];
    }

    public static function create(): self
    {
        return ObjectManager::getInstance(self::class);
    }
}
