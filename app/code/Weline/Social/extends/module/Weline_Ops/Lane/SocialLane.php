<?php

declare(strict_types=1);

namespace Weline\Social\Extends\Module\Weline_Ops\Lane;

use Weline\Framework\Http\Url;
use Weline\Ops\Api\OpsLaneContributionInterface;
use Weline\Ops\Service\OpsLaneDeepLink;

final class SocialLane implements OpsLaneContributionInterface
{
    public function __construct(
        private readonly Url $url,
    ) {
    }

    public function getLaneCode(): string
    {
        return 'social';
    }

    public function getTitle(): string
    {
        return (string)__('社媒连接');
    }

    public function getSortOrder(): int
    {
        return 30;
    }

    public function getStatus(): string
    {
        return 'deeplink';
    }

    public function getActions(int $websiteId): array
    {
        return [
            [
                'code' => 'social_accounts',
                'label' => (string)__('社媒账户'),
                'url' => OpsLaneDeepLink::socialAdmin($this->url, $websiteId),
                'connected' => null,
            ],
        ];
    }

    public function getTaskTypes(): array
    {
        return [
            ['type' => 'social.connect_account', 'label' => (string)__('连接社媒账号')],
        ];
    }

    public function getConnectionHealth(int $websiteId): array
    {
        $connected = false;
        $summary = (string)__('尚未检测到已绑定社媒账户');
        try {
            if (class_exists(\Weline\Social\Model\SocialWebsiteAccount::class)) {
                /** @var \Weline\Social\Model\SocialWebsiteAccount $model */
                $model = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Social\Model\SocialWebsiteAccount::class);
                $items = $model->clear()
                    ->where('website_id', max(0, $websiteId))
                    ->select()
                    ->fetch()
                    ->getItems();
                $connected = is_countable($items) && count($items) > 0;
                $summary = $connected
                    ? (string)__('已有站点社媒绑定')
                    : (string)__('请到社媒模块完成账户绑定');
            }
        } catch (\Throwable) {
            $summary = (string)__('无法读取社媒绑定状态');
        }

        return ['connected' => $connected, 'summary' => $summary];
    }
}
