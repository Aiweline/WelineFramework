<?php

declare(strict_types=1);

namespace Weline\Websites\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;
use Weline\Websites\Model\DomainPool;

/**
 * 建站任务：域名与 HTTPS
 */
class WebsitesDomainHttpsSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $href = $this->backendPath('websites/admin/domain/index');
        $done = $this->hasDomainPoolRow();
        $status = $done ? 'done' : 'todo';
        $tip = $done
            ? (string)__('域名池已有可建站域名记录。')
            : (string)__('域名池可建站、证书签发；迁站重点核对解析与回调白名单。');

        return $this->tasks([[
            'code' => 'domain_https',
            'sort' => 140,
            'category' => (string)__('基建'),
            'module' => 'Weline_Websites',
            'title' => (string)__('域名与 HTTPS'),
            'tip' => $tip,
            'status' => $status,
            'href' => $href,
            'scenarios' => ['new', 'migrate'],
            'meta' => ['configured' => $done],
        ]]);
    }

    private function hasDomainPoolRow(): bool
    {
        try {
            /** @var DomainPool $model */
            $model = ObjectManager::getInstance(DomainPool::class);
            $items = $model->clear()->limit(1)->select()->fetch()->getItems();
            return is_array($items) && $items !== [];
        } catch (\Throwable) {
            return false;
        }
    }
}
