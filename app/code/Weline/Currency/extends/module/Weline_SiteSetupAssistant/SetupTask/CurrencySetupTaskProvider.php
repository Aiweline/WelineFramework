<?php

declare(strict_types=1);

namespace Weline\Currency\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;
use Weline\Currency\Model\Currency;

/**
 * 建站任务：货币与汇率
 */
class CurrencySetupTaskProvider extends AbstractSetupTaskProvider
{
    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $href = $this->backendPath('currency/backend/config/index');
        $done = $this->hasCurrency();
        $status = $done ? 'done' : 'todo';
        $tip = $done
            ? (string)__('已检测到货币配置入口可用；请确认默认币与汇率源。')
            : (string)__('站点可用货币、默认币与汇率源。');

        return $this->tasks([[
            'code' => 'currency',
            'sort' => 100,
            'category' => (string)__('本地化'),
            'module' => 'Weline_Currency',
            'title' => (string)__('货币与汇率'),
            'tip' => $tip,
            'status' => $status,
            'href' => $href,
            'scenarios' => ['new', 'migrate'],
            'meta' => ['configured' => $done],
        ]]);
    }

    private function hasCurrency(): bool
    {
        try {
            /** @var Currency $model */
            $model = ObjectManager::getInstance(Currency::class);
            $items = $model->clear()->limit(1)->select()->fetch()->getItems();
            return is_array($items) && $items !== [];
        } catch (\Throwable) {
            return false;
        }
    }
}
