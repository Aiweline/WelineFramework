<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Extends\Module\Weline_Framework\Query;

use WeShop\Affiliate\Service\AffiliateService;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class AffiliateQueryProvider implements QueryProviderInterface
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly AffiliateService $affiliateService,
        private readonly Url $url,
        private readonly ?CustomerSession $customerSession = null
    ) {
    }

    public function getProviderName(): string
    {
        return 'affiliate';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getProductShareLinks' => $this->getProductShareLinks($params),
            'getShareLink' => $this->getShareLink($params),
            'recordOutboundShare' => $this->recordOutboundShare($params),
            'requestWithdrawal' => $this->requestWithdrawal($params),
            'getMySummary' => $this->getMySummary(),
            default => throw new \InvalidArgumentException(
                (string) __('分销查询器不支持该操作：%{1}', [$operation])
            ),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getShareLink(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        try {
            $data = $this->affiliateService->getShareLink(
                $customerId,
                (string) ($params['target_url'] ?? $params['target_path'] ?? ''),
                (string) ($params['channel'] ?? '')
            );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'message' => (string) __('推广链接已生成。'),
            'data' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getProductShareLinks(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        $productId = (int) ($params['product_id'] ?? 0);
        if ($productId <= 0) {
            return [
                'success' => false,
                'message' => (string) __('缺少商品 ID。'),
            ];
        }

        $data = $this->affiliateService->getProductShareLinks(
            $customerId,
            $productId,
            (string) ($params['channel'] ?? '')
        );

        return [
            'success' => true,
            'message' => (string) __('分享链接已生成。'),
            'data' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function recordOutboundShare(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        $shareCode = (string) ($params['share_code'] ?? '');
        $platform = (string) ($params['platform'] ?? '');
        if ($shareCode === '' || $platform === '') {
            return [
                'success' => false,
                'message' => (string) __('缺少分享码或分享平台。'),
            ];
        }

        $data = $this->affiliateService->recordOutboundShare($shareCode, $platform, $customerId, [
            'source' => 'query_provider',
        ]);

        return [
            'success' => true,
            'message' => (string) __('分享动作已记录。'),
            'data' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function requestWithdrawal(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        try {
            $data = $this->affiliateService->requestWithdrawal(
                $customerId,
                (float) ($params['amount'] ?? 0),
                (string) ($params['method'] ?? 'manual'),
                (string) ($params['account_label'] ?? ''),
                [
                    'source' => 'account_center',
                    'note' => (string) ($params['note'] ?? ''),
                ]
            );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'message' => (string) __('提现申请已提交。'),
            'data' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getMySummary(): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        return [
            'success' => true,
            'message' => (string) __('分销数据已加载。'),
            'data' => $this->affiliateService->getAffiliateSummary($customerId),
        ];
    }

    private function getCustomerId(): int
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId > 0) {
            return $customerId;
        }

        try {
            return (int) ($this->getCustomerSession()->getUserId() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function getCustomerSession(): CustomerSession
    {
        return $this->customerSession ?? ObjectManager::getInstance(CustomerSession::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function loginRequired(): array
    {
        return [
            'success' => false,
            'message' => (string) __('请先登录。'),
            'data' => [
                'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
            ],
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'affiliate',
            'name' => __('分销查询'),
            'description' => __('提供商品分享链接、分享动作记录和分销数据汇总。'),
            'module' => 'WeShop_Affiliate',
            'operations' => [
                [
                    'name' => 'getProductShareLinks',
                    'description' => __('为当前分销用户生成或复用商品分享链接。'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'product_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'channel' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Get product affiliate share links',
                ],
                [
                    'name' => 'recordOutboundShare',
                    'description' => __('记录复制链接、二维码或社交平台发出分享动作。'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'share_code' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'platform' => ['type' => 'string', 'required' => true, 'max_length' => 32],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Record affiliate outbound share',
                ],
                [
                    'name' => 'getShareLink',
                    'description' => __('为当前分销用户生成首页或自定义站内页面推广链接。'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'target_url' => ['type' => 'string', 'required' => false, 'max_length' => 255],
                        'channel' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Get affiliate landing share link',
                ],
                [
                    'name' => 'getMySummary',
                    'description' => __('返回当前分销用户的分享、转化和佣金汇总。'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 5,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Get current affiliate summary',
                ],
                [
                    'name' => 'requestWithdrawal',
                    'description' => __('提交当前分销用户的佣金提现申请，并保留提现记录。'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'amount' => ['type' => 'number', 'required' => true, 'min' => 0.01],
                        'method' => ['type' => 'string', 'required' => false, 'max_length' => 40],
                        'account_label' => ['type' => 'string', 'required' => false, 'max_length' => 160],
                        'note' => ['type' => 'string', 'required' => false, 'max_length' => 255],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Request affiliate commission withdrawal',
                ],
            ],
        ];
    }
}
