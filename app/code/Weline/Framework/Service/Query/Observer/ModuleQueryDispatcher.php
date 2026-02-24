<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 动态模块查询分发器
 *
 * 当 dispatch('{Module_Name}::query', $eventData) 时，自动将请求路由到
 * FrameworkQueryService::execute()，由 QueryProviderRegistry 中已注册的查询器处理。
 *
 * 约定：Weline_Widget::query -> provider=widget，Weline_Order::query -> provider=order
 * 转换规则：取模块名最后一段，转小写
 */
class ModuleQueryDispatcher implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $eventName = $event->getName();
        if ($eventName === '' || !str_contains($eventName, '::query')) {
            return;
        }

        $module = explode('::', $eventName)[0] ?? '';
        if ($module === '') {
            return;
        }

        $provider = $this->moduleToProvider($module);
        $innerData = $event->getData('data');
        if (!is_array($innerData)) {
            $innerData = ['operation' => '', 'params' => []];
        }
        $operation = (string)($innerData['operation'] ?? '');
        $params = (array)($innerData['params'] ?? []);
        $area = (string)($innerData['area'] ?? 'backend');

        if ($operation === '') {
            $event->setData('error', (string)__('缺少 operation 参数'));
            return;
        }

        try {
            /** @var FrameworkQueryService $queryService */
            $queryService = ObjectManager::getInstance(FrameworkQueryService::class);
            $result = $queryService->execute($provider, $operation, $params, $area);
            $event->setData('result', $result);
            $event->setData('error', '');
        } catch (\Throwable $e) {
            $event->setData('result', null);
            $event->setData('error', $e->getMessage());
        }
    }

    /**
     * 模块名转 provider 标识
     * Weline_Widget -> widget, Weline_Order -> order, Vendor_Module -> module
     */
    private function moduleToProvider(string $module): string
    {
        $parts = explode('_', $module);
        $last = end($parts);
        return $last !== false ? strtolower($last) : strtolower($module);
    }
}
