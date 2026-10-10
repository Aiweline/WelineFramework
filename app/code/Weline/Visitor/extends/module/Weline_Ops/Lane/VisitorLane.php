<?php

declare(strict_types=1);

namespace Weline\Visitor\Extends\Module\Weline_Ops\Lane;

use Weline\Framework\Http\Url;
use Weline\Ops\Api\OpsLaneContributionInterface;

final class VisitorLane implements OpsLaneContributionInterface
{
    public function __construct(
        private readonly Url $url,
    ) {
    }

    public function getLaneCode(): string
    {
        return 'insight';
    }

    public function getTitle(): string
    {
        return (string)__('站内事件洞察');
    }

    public function getSortOrder(): int
    {
        return 25;
    }

    public function getStatus(): string
    {
        return 'deeplink';
    }

    public function getActions(int $websiteId): array
    {
        return [
            [
                'code' => 'visitor_dashboard',
                'label' => (string)__('Visitor 分析'),
                'url' => $this->url->getBackendUrl('visitor/backend/pixel-dashboard/index'),
                'connected' => null,
            ],
        ];
    }

    public function getTaskTypes(): array
    {
        return [
            ['type' => 'insight.enable_pixel', 'label' => (string)__('确认像素/事件采集就绪')],
        ];
    }

    public function getConnectionHealth(int $websiteId): array
    {
        $ready = class_exists(\Weline\Visitor\Controller\Backend\PixelDashboard::class);

        return [
            'connected' => $ready,
            'summary' => $ready
                ? (string)__('Visitor 事件引擎可用，将作为日更分析主信号之一')
                : (string)__('Visitor 模块不可用'),
        ];
    }
}
