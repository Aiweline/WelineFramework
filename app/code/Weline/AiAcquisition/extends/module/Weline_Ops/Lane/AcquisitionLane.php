<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Extends\Module\Weline_Ops\Lane;

use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Model\LeadRun;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Api\OpsLaneContributionInterface;
use Weline\Ops\Service\OpsLaneDeepLink;

/**
 * AI 运营台 · 获客车道。
 *
 * 获客是站点级 AI 运营能力：线索、运行与画像都按 website_id 归档；
 * 本车道把「获客」接到 AI 运营台，而不是挂在 AI 中心的工具列表里。
 */
final class AcquisitionLane implements OpsLaneContributionInterface
{
    public function __construct(
        private readonly Url $url,
    ) {
    }

    public function getLaneCode(): string
    {
        return 'acquisition';
    }

    public function getTitle(): string
    {
        return (string)__('AI 获客');
    }

    public function getSortOrder(): int
    {
        return 30;
    }

    public function getStatus(): string
    {
        return 'ready';
    }

    public function getActions(int $websiteId): array
    {
        return [
            [
                'code' => 'acquisition_run',
                'label' => (string)__('创建获客运行'),
                'url' => OpsLaneDeepLink::backend($this->url, 'ai_acquisition/backend/run', $websiteId),
                'connected' => null,
            ],
            [
                'code' => 'acquisition_leads',
                'label' => (string)__('查看客户线索'),
                'url' => OpsLaneDeepLink::backend($this->url, 'ai_acquisition/backend/lead', $websiteId),
                'connected' => null,
            ],
        ];
    }

    public function getTaskTypes(): array
    {
        return [
            ['type' => 'acquisition.discover', 'label' => (string)__('公开网络获客')],
            ['type' => 'acquisition.review', 'label' => (string)__('线索复核与跟进')],
        ];
    }

    public function getConnectionHealth(int $websiteId): array
    {
        $websiteId = max(0, $websiteId);
        $leads = 0;
        $runs = 0;
        try {
            $leads = (int)ObjectManager::getInstance(Lead::class)
                ->reset()
                ->clearQuery()
                ->where(Lead::schema_fields_WEBSITE_ID, $websiteId)
                ->total();
            $runs = (int)ObjectManager::getInstance(LeadRun::class)
                ->reset()
                ->clearQuery()
                ->where(LeadRun::schema_fields_WEBSITE_ID, $websiteId)
                ->total();
        } catch (\Throwable) {
            // 表尚未安装时保持未连接
        }

        return [
            'connected' => $leads > 0 || $runs > 0,
            'summary' => (string)__('已入库 %{1} 条线索 · %{2} 次运行', [(string)$leads, (string)$runs]),
            'detail' => ['leads' => $leads, 'runs' => $runs],
        ];
    }
}
