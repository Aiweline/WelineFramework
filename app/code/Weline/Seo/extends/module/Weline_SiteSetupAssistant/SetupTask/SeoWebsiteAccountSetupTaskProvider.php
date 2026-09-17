<?php

declare(strict_types=1);

namespace Weline\Seo\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;
use Weline\Seo\Model\SeoWebsiteAccount;

/**
 * 建站任务：SEO 站点账户绑定
 */
class SeoWebsiteAccountSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $href = $this->backendPath('seo/backend/website-account');
        $done = $this->hasSeoWebsiteAccount((int)($context['website_id'] ?? 0));
        $status = $done ? 'done' : 'todo';
        $tip = $done
            ? (string)__('已绑定 SEO 站点账户。')
            : (string)__('绑定 Search Console / 分析账户后才能推送与诊断。');

        return $this->tasks([[
            'code' => 'seo_bind',
            'sort' => 80,
            'category' => (string)__('SEO'),
            'module' => 'Weline_Seo',
            'title' => (string)__('SEO 站点账户绑定'),
            'tip' => $tip,
            'status' => $status,
            'href' => $href,
            'scenarios' => ['new', 'migrate'],
            'meta' => ['configured' => $done],
        ]]);
    }

    private function hasSeoWebsiteAccount(int $websiteId): bool
    {
        try {
            /** @var SeoWebsiteAccount $model */
            $model = ObjectManager::getInstance(SeoWebsiteAccount::class);
            $q = $model->clear();
            if ($websiteId > 0) {
                $q->where('website_id', $websiteId);
            }
            $items = $q->select()->fetch()->getItems();
            return is_array($items) && $items !== [];
        } catch (\Throwable) {
            return false;
        }
    }
}
