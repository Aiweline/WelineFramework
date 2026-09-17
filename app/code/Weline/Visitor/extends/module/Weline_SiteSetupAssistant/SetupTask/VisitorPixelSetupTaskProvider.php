<?php

declare(strict_types=1);

namespace Weline\Visitor\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;

/**
 * 建站任务：访客像素追踪（运行时默认开启；继承/默认启用视为完成）
 */
class VisitorPixelSetupTaskProvider extends AbstractSetupTaskProvider
{
    private const MODULE = 'Weline_Visitor';
    private const AREA = 'backend';
    private const KEY = 'visitor/tracking/pixel_enabled';

    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $href = $this->systemConfigPath(self::MODULE, self::AREA, self::KEY, (string)__('像素追踪'));
        // 与 VisitorTrackingConfig 运行时默认 true 对齐
        $prov = $this->resolveConfigProvenance(self::MODULE, self::AREA, self::KEY, $scope, true);
        $done = $this->isTruthy($prov['value']);
        $status = $done ? 'done' : 'todo';
        if ($done && !empty($prov['from_default'])) {
            $tip = (string)__('像素追踪默认已开启（当前范围无覆盖关闭）。');
        } elseif ($done && !empty($prov['inherited'])) {
            $tip = (string)__('已继承上级范围开启访客像素追踪。');
        } elseif ($done) {
            $tip = (string)__('已开启访客像素追踪。');
        } else {
            $tip = (string)__('开启像素后才能在仪表盘看真实转化漏斗。');
        }

        return $this->tasks([[
            'code' => 'visitor_pixel',
            'sort' => 110,
            'category' => (string)__('增长'),
            'module' => 'Weline_Visitor',
            'title' => (string)__('访客像素追踪'),
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
