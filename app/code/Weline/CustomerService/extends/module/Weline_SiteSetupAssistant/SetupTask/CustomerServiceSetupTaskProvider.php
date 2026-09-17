<?php

declare(strict_types=1);

namespace Weline\CustomerService\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\CustomerService\Service\CustomerServiceSettings;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;

/**
 * 建站任务：在线客服入口（默认启用 + 继承全局视为完成）
 */
class CustomerServiceSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $href = $this->systemConfigPath(
            CustomerServiceSettings::MODULE,
            CustomerServiceSettings::AREA,
            CustomerServiceSettings::KEY_ENABLED,
            (string)__('在线客服')
        );
        $prov = $this->resolveConfigProvenance(
            CustomerServiceSettings::MODULE,
            CustomerServiceSettings::AREA,
            CustomerServiceSettings::KEY_ENABLED,
            $scope,
            true, // 与 CustomerServiceSettings::isServiceEnabled 默认一致
        );
        $done = $this->isTruthy($prov['value']);
        $status = $done ? 'done' : 'todo';
        if ($done && !empty($prov['from_default'])) {
            $tip = (string)__('默认已启用在线客服（当前范围无覆盖关闭）。');
        } elseif ($done && !empty($prov['inherited'])) {
            $tip = (string)__('已继承上级范围启用在线客服。');
        } elseif ($done) {
            $tip = (string)__('已启用在线客服入口。');
        } else {
            $tip = (string)__('启用前台客服与默认语言；可选 AI 模型。');
        }

        return $this->tasks([[
            'code' => 'customer_service',
            'sort' => 120,
            'category' => (string)__('服务'),
            'module' => 'Weline_CustomerService',
            'title' => (string)__('在线客服入口'),
            'tip' => $tip,
            'status' => $status,
            'href' => $href,
            'scenarios' => ['new'],
            'meta' => [
                'configured' => $done,
                'inherited' => !empty($prov['inherited']),
                'from_default' => !empty($prov['from_default']),
                'source_scope' => (string)($prov['source_scope'] ?? ''),
            ],
        ]]);
    }
}
