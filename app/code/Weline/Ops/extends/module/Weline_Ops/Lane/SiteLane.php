<?php

declare(strict_types=1);

namespace Weline\Ops\Extends\Module\Weline_Ops\Lane;

use Weline\Framework\Http\Url;
use Weline\Ops\Api\OpsLaneContributionInterface;
use Weline\Ops\Service\OpsWebsiteContextReader;

final class SiteLane implements OpsLaneContributionInterface
{
    public function __construct(
        private readonly Url $url,
        private readonly OpsWebsiteContextReader $contextReader,
    ) {
    }

    public function getLaneCode(): string
    {
        return 'site';
    }

    public function getTitle(): string
    {
        return (string)__('站点画像');
    }

    public function getSortOrder(): int
    {
        return 10;
    }

    public function getStatus(): string
    {
        return 'ready';
    }

    public function getActions(int $websiteId): array
    {
        $actions = [
            [
                'code' => 'ai_agents',
                'label' => (string)__('AI 智能体治理'),
                'url' => $this->url->getBackendUrl('ai/backend/agent'),
                'connected' => null,
            ],
        ];
        if (class_exists(\Weline\Websites\Controller\Backend\Website::class)) {
            $actions[] = [
                'code' => 'websites',
                'label' => (string)__('网站管理'),
                'url' => $this->url->getBackendUrl('websites/backend/website/index'),
                'connected' => null,
            ];
        }

        return $actions;
    }

    public function getTaskTypes(): array
    {
        return [
            ['type' => 'site.refresh_persona', 'label' => (string)__('刷新画像')],
            ['type' => 'site.connect_gap', 'label' => (string)__('补齐连接缺口')],
        ];
    }

    public function getConnectionHealth(int $websiteId): array
    {
        $ctx = $this->contextReader->read($websiteId);
        $ok = (bool)($ctx['available'] ?? false) && trim((string)($ctx['description'] ?? '')) !== '';

        return [
            'connected' => $ok,
            'summary' => $ok
                ? (string)__('已读取网站描述，可生成画像')
                : (string)__('请补充网站描述后再生成画像'),
            'detail' => $ctx,
        ];
    }
}
