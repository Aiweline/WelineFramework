<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\I18n\Api\Rest\V1\RemoteTranslation;
use Weline\I18n\Service\RemoteTranslationApiDemoDescriptor;

/** API 收集后仅为远程协助翻译接口附加本模块 Demo 声明。 */
final class ApiDocDemoObserver implements \Weline\Framework\Event\ObserverInterface
{
    public function execute(Event &$event): void
    {
        $groups = $event->getData('apis');
        if (!is_array($groups)) {
            return;
        }
        $demo = RemoteTranslationApiDemoDescriptor::describe();
        $methods = ['postPending', 'postIngest', 'postCollectStart', 'getCollectStatus'];
        foreach ($groups as &$apis) {
            if (!is_array($apis)) {
                continue;
            }
            foreach ($apis as &$api) {
                if (!is_array($api)
                    || ($api['module'] ?? '') !== 'Weline_I18n'
                    || ltrim((string)($api['class'] ?? ''), '\\') !== RemoteTranslation::class
                    || !in_array((string)($api['method'] ?? ''), $methods, true)
                ) {
                    continue;
                }
                $api['demo'] = $demo;
            }
            unset($api);
        }
        unset($apis);
        $event->setData('apis', $groups);
    }
}
