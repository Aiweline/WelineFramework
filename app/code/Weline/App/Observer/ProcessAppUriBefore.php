<?php

declare(strict_types=1);

namespace Weline\App\Observer;

use Weline\App\Controller\Router as AppRouter;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * process_uri_before 事件适配器：把路由改写委托给 App\Controller\Router。
 *
 * 路由规则（/app/download、/manifest.webmanifest、/sw.js、PWA 图标）只在 Router 里维护；
 * 本类不持有任何路径表，避免事件层与路由层双权威。
 */
final class ProcessAppUriBefore implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var DataObject|null $data */
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }
        $path = $data->getData('path');
        if (!\is_string($path)) {
            return;
        }
        $rule = $data->getData('rule');
        if ($rule instanceof DataObject && $rule->getData('module')) {
            return;
        }

        $ruleArray = $rule instanceof DataObject ? $rule->getData() : (\is_array($rule) ? $rule : []);
        AppRouter::process($path, $ruleArray);

        if (!empty($ruleArray['module'])) {
            $data->setData('path', $path);
            if ($rule instanceof DataObject) {
                $rule->setData($ruleArray);
            } else {
                $data->setData('rule', new DataObject($ruleArray));
            }
        }
    }
}
