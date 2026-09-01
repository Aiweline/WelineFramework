<?php

declare(strict_types=1);

namespace Weline\Marketing\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Marketing\Model\Rule\Rule;

final class MarketingAdminQueryProvider implements QueryProviderInterface
{
    public const ACL_SOURCE = 'Weline_Marketing::commerce:marketing:rules';

    public function getProviderName(): string
    {
        return 'marketing_admin';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        try {
            return match ($operation) {
                'listRules' => $this->listRules($params),
                default => throw new \InvalidArgumentException((string)__('营销后台接口不支持操作：%{1}', [$operation])),
            };
        } catch (\InvalidArgumentException $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        } catch (\Throwable) {
            return ['success' => false, 'message' => (string)__('营销后台服务暂时不可用。')];
        }
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'marketing_admin',
            'name' => (string)__('万能优惠规则后台'),
            'description' => (string)__('读取营销规则摘要，供后台浏览器 API 使用。'),
            'module' => 'Weline_Marketing',
            'operations' => [
                [
                    'name' => 'listRules',
                    'frontend' => true,
                    'backend' => true,
                    'mode' => 'read',
                    'auth' => 'backend',
                    'backend_acl' => ['kind' => 'source', 'source_id' => self::ACL_SOURCE],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $params */
    private function listRules(array $params): array
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = min(50, max(1, (int)($params['page_size'] ?? 20)));
        $rule = \Weline\Framework\Manager\ObjectManager::getInstance(Rule::class);
        $rule->order(Rule::schema_fields_PRIORITY, 'DESC');
        $collection = $rule->pagination($page, $pageSize)->select()->fetch();
        $items = [];
        foreach ($collection->getItems() as $item) {
            $items[] = [
                'id' => (int)$item->getId(),
                'name' => (string)$item->getData(Rule::schema_fields_NAME),
                'rule_type' => (string)$item->getData(Rule::schema_fields_RULE_TYPE),
                'status' => (string)$item->getData(Rule::schema_fields_STATUS),
                'priority' => (int)$item->getData(Rule::schema_fields_PRIORITY),
            ];
        }

        return [
            'success' => true,
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => (int)$collection->getTotal(),
            ],
        ];
    }
}
