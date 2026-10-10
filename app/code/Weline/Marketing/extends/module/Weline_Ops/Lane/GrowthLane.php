<?php

declare(strict_types=1);

namespace Weline\Marketing\Extends\Module\Weline_Ops\Lane;

use Weline\Framework\Http\Url;
use Weline\Ops\Api\OpsLaneContributionInterface;
use Weline\Ops\Service\OpsLaneDeepLink;

final class GrowthLane implements OpsLaneContributionInterface
{
    public function __construct(
        private readonly Url $url,
    ) {
    }

    public function getLaneCode(): string
    {
        return 'growth';
    }

    public function getTitle(): string
    {
        return (string)__('增长运营');
    }

    public function getSortOrder(): int
    {
        return 50;
    }

    public function getStatus(): string
    {
        return 'deeplink';
    }

    public function getActions(int $websiteId): array
    {
        return [
            [
                'code' => 'winback',
                'label' => (string)__('挽回活动'),
                'url' => OpsLaneDeepLink::backend($this->url, 'marketing/backend/winback/index', $websiteId),
                'connected' => null,
            ],
            [
                'code' => 'winback_dashboard',
                'label' => (string)__('挽回看板'),
                'url' => OpsLaneDeepLink::backend($this->url, 'marketing/backend/winbackdashboard/index', $websiteId),
                'connected' => null,
            ],
            [
                'code' => 'lifecycle',
                'label' => (string)__('生命周期'),
                'url' => OpsLaneDeepLink::backend($this->url, 'marketing/backend/lifecycle/index', $websiteId),
                'connected' => null,
            ],
            [
                'code' => 'segment',
                'label' => (string)__('营销分群'),
                'url' => OpsLaneDeepLink::backend($this->url, 'marketing/backend/segment/index', $websiteId),
                'connected' => null,
            ],
        ];
    }

    public function getTaskTypes(): array
    {
        return [
            ['type' => 'growth.review_winback', 'label' => (string)__('复查挽回活动')],
        ];
    }

    public function getConnectionHealth(int $websiteId): array
    {
        return [
            'connected' => true,
            'summary' => (string)__('Marketing 增长能力已挂接运营台'),
        ];
    }
}
