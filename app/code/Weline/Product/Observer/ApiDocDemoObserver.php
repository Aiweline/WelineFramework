<?php

declare(strict_types=1);

namespace Weline\Product\Observer;

use Weline\Framework\Event\Event;
use Weline\Product\Api\Rest\V1\Products;
use Weline\Product\Service\ProductApiDemoDescriptor;

/** API 收集后仅为产品接口附加本模块 Demo 声明。 */
final class ApiDocDemoObserver implements \Weline\Framework\Event\ObserverInterface
{
    public function execute(Event &$event): void
    {
        $groups = $event->getData('apis');
        if (!is_array($groups)) {
            return;
        }
        $demo = ProductApiDemoDescriptor::describe();
        foreach ($groups as &$apis) {
            if (!is_array($apis)) {
                continue;
            }
            foreach ($apis as &$api) {
                if (!is_array($api)
                    || ($api['module'] ?? '') !== 'Weline_Product'
                    || ltrim((string)($api['class'] ?? ''), '\\') !== Products::class
                    || !in_array((string)($api['method'] ?? ''), ['postCreate', 'putEdit', 'postPublish', 'getDetail'], true)
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
