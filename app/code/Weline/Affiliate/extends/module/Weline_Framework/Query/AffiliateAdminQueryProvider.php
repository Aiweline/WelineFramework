<?php

declare(strict_types=1);

namespace Weline\Affiliate\Extends\Module\Weline_Framework\Query;

use Weline\Affiliate\Service\AffiliateAdminPageDataService;
use Weline\Affiliate\Service\AffiliateService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

final class AffiliateAdminQueryProvider implements QueryProviderInterface
{
    public const ACL_SOURCE = 'Weline_Affiliate::commerce:affiliate:programs';

    public function __construct(
        private readonly AffiliateService $affiliateService,
        private readonly AffiliateAdminPageDataService $adminPageDataService,
    ) {
    }

    public function getProviderName(): string
    {
        return 'affiliate_admin';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        try {
            return match ($operation) {
                'listAffiliates' => $this->listAffiliates($params),
                'getSummary' => $this->getSummary(),
                default => throw new \InvalidArgumentException((string) \__('分销后台接口不支持操作：%{1}', [$operation])),
            };
        } catch (\InvalidArgumentException $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        } catch (\Throwable) {
            return ['success' => false, 'message' => (string) \__('分销后台服务暂时不可用。')];
        }
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'affiliate_admin',
            'name' => (string) \__('万能分销后台'),
            'description' => (string) \__('读取分销账户摘要与分页列表，供后台浏览器 API 使用。'),
            'module' => 'Weline_Affiliate',
            'operations' => [
                [
                    'name' => 'listAffiliates',
                    'frontend' => false,
                    'backend' => true,
                    'mode' => 'read',
                    'auth' => 'backend',
                    'backend_acl' => ['kind' => 'source', 'source_id' => self::ACL_SOURCE],
                    'params' => [
                        'page' => ['type' => 'int', 'required' => false, 'min' => 1],
                        'page_size' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 50],
                        'customer_id' => ['type' => 'int', 'required' => false, 'min' => 1],
                        'referral_code' => ['type' => 'string', 'required' => false, 'max_length' => 50],
                        'status' => ['type' => 'string', 'required' => false, 'max_length' => 20],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'List affiliate accounts for admin',
                ],
                [
                    'name' => 'getSummary',
                    'frontend' => false,
                    'backend' => true,
                    'mode' => 'read',
                    'auth' => 'backend',
                    'backend_acl' => ['kind' => 'source', 'source_id' => self::ACL_SOURCE],
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Get affiliate admin summary counters',
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $params */
    private function listAffiliates(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($params['page_size'] ?? 20)));
        $filters = array_filter([
            'customer_id' => (int) ($params['customer_id'] ?? 0) ?: null,
            'referral_code' => trim((string) ($params['referral_code'] ?? '')),
            'status' => trim((string) ($params['status'] ?? '')),
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        $pageData = $this->adminPageDataService->getPageData($page, $pageSize, $filters);

        return [
            'success' => true,
            'items' => $pageData['affiliateRecords'] ?? [],
            'pagination' => $pageData['pagination'] ?? [],
            'summary' => $pageData['summary'] ?? [],
            'status_options' => $this->affiliateService->getStatusOptions(),
        ];
    }

    private function getSummary(): array
    {
        return [
            'success' => true,
            'data' => $this->adminPageDataService->getSummary(),
        ];
    }
}
