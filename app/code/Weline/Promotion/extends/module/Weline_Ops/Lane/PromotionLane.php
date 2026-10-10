<?php

declare(strict_types=1);

namespace Weline\Promotion\Extends\Module\Weline_Ops\Lane;

use Weline\Framework\Http\Url;
use Weline\Ops\Api\OpsLaneContributionInterface;

final class PromotionLane implements OpsLaneContributionInterface
{
    public function __construct(
        private readonly Url $url,
    ) {
    }

    public function getLaneCode(): string
    {
        return 'promotion';
    }

    public function getTitle(): string
    {
        return (string)__('促销运营');
    }

    public function getSortOrder(): int
    {
        return 60;
    }

    public function getStatus(): string
    {
        return 'deeplink';
    }

    public function getActions(int $websiteId): array
    {
        return [
            [
                'code' => 'promotion_desk',
                'label' => (string)__('促销运营台'),
                'url' => $this->url->getBackendUrl('promotion/backend/promotion/index'),
                'connected' => null,
            ],
            [
                'code' => 'promotion_theme',
                'label' => (string)__('活动主题'),
                'url' => $this->url->getBackendUrl('promotion/backend/theme/index'),
                'connected' => null,
            ],
        ];
    }

    public function getTaskTypes(): array
    {
        return [
            ['type' => 'promotion.review_desk', 'label' => (string)__('复查促销运营台')],
        ];
    }

    public function getConnectionHealth(int $websiteId): array
    {
        return [
            'connected' => true,
            'summary' => (string)__('促销运营台已挂接'),
        ];
    }
}
