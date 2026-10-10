<?php

declare(strict_types=1);

namespace Weline\Seo\Extends\Module\Weline_Ops\Lane;

use Weline\Framework\Http\Url;
use Weline\Ops\Api\OpsLaneContributionInterface;

final class SeoLane implements OpsLaneContributionInterface
{
    public function __construct(
        private readonly Url $url,
    ) {
    }

    public function getLaneCode(): string
    {
        return 'seo';
    }

    public function getTitle(): string
    {
        return (string)__('GSC / SEO');
    }

    public function getSortOrder(): int
    {
        return 20;
    }

    public function getStatus(): string
    {
        return 'deeplink';
    }

    public function getActions(int $websiteId): array
    {
        return [
            [
                'code' => 'seo_accounts',
                'label' => (string)__('SEO 账户'),
                'url' => $this->url->getBackendUrl('seo/backend/account/index'),
                'connected' => null,
            ],
        ];
    }

    public function getTaskTypes(): array
    {
        return [
            ['type' => 'seo.connect_gsc', 'label' => (string)__('连接 Google Search Console')],
        ];
    }

    public function getConnectionHealth(int $websiteId): array
    {
        $connected = false;
        $summary = (string)__('尚未绑定 SEO 账户');
        try {
            if (class_exists(\Weline\Seo\Model\SeoWebsiteAccount::class)) {
                /** @var \Weline\Seo\Model\SeoWebsiteAccount $model */
                $model = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Seo\Model\SeoWebsiteAccount::class);
                $items = $model->clear()
                    ->where('website_id', max(0, $websiteId))
                    ->select()
                    ->fetch()
                    ->getItems();
                $connected = is_countable($items) && count($items) > 0;
                $summary = $connected
                    ? (string)__('站点已绑定 SEO 账户（含 GSC 通道时可用于日更分析）')
                    : (string)__('请到 SEO 模块绑定 Google 等账户');
            }
        } catch (\Throwable) {
            $summary = (string)__('无法读取 SEO 绑定状态');
        }

        return ['connected' => $connected, 'summary' => $summary];
    }
}
