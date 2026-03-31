<?php

declare(strict_types=1);

namespace WeShop\Social\Extends\Module\Weline_Framework\Query;

use WeShop\Social\Model\SocialShare;
use WeShop\Social\Service\SocialService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class SocialQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SocialService $socialService
    ) {
    }

    public function getProviderName(): string
    {
        return 'social';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'recordShare' => $this->recordShare($params),
            'getShareCount' => $this->socialService->getShareCount(
                (int) ($params['product_id'] ?? 0),
                isset($params['platform']) ? (string) $params['platform'] : null
            ),
            'getShareCounts' => $this->socialService->getShareCounts(
                is_array($params['product_ids'] ?? null) ? $params['product_ids'] : []
            ),
            'getFooterSocialLinks' => $this->socialService->getFooterSocialLinks(
                is_array($params['context'] ?? null) ? $params['context'] : []
            ),
            'getProductShareUrls' => $this->socialService->getProductShareUrls(
                (string) ($params['url'] ?? ''),
                (string) ($params['title'] ?? ''),
                is_array($params['platforms'] ?? null) ? $params['platforms'] : []
            ),
            default => throw new \InvalidArgumentException(
                (string) __('Social query provider does not support operation: %{1}', [$operation])
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'social',
            'name' => __('Social Query'),
            'description' => __('Provides social-share tracking, footer links, and product share URLs.'),
            'module' => 'WeShop_Social',
            'operations' => [
                ['name' => 'recordShare', 'description' => __('Persist a social-share record.')],
                ['name' => 'getShareCount', 'description' => __('Count social shares for a product.')],
                ['name' => 'getShareCounts', 'description' => __('Count social shares for multiple products.')],
                ['name' => 'getFooterSocialLinks', 'description' => __('Return configured footer social links.')],
                ['name' => 'getProductShareUrls', 'description' => __('Build share URLs for a product or page.')],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function recordShare(array $params): array
    {
        $share = $this->socialService->recordShare($params);

        return [
            'share_id' => (int) ($share->getId() ?? 0),
            SocialShare::schema_fields_CUSTOMER_ID => (int) ($share->getData(SocialShare::schema_fields_CUSTOMER_ID) ?? 0),
            SocialShare::schema_fields_PRODUCT_ID => (int) ($share->getData(SocialShare::schema_fields_PRODUCT_ID) ?? 0),
            SocialShare::schema_fields_PLATFORM => (string) ($share->getData(SocialShare::schema_fields_PLATFORM) ?? ''),
        ];
    }
}
