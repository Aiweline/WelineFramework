<?php

declare(strict_types=1);

namespace Weline\Checkout\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * Claim checkout public paths as the same nested layout path (layouts/checkout/success/default.phtml).
 */
final class LayoutResolveObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject || (bool)$data->getData('claimed')) {
            return;
        }

        $requestPath = strtolower(trim(str_replace('\\', '/', (string)$data->getData('request_path')), '/'));
        if ($requestPath === 'checkout'
            || $requestPath === 'checkout/success'
            || $requestPath === 'checkout/failure'
            || $requestPath === 'checkout/failer'
        ) {
            $layoutPath = $requestPath === 'checkout/failer' ? 'checkout/failure' : $requestPath;
            $data->setData('claimed', true);
            $data->setData('layout_path', $layoutPath);
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', '');
            $data->setData('entity_kind', '');
        }
    }
}
