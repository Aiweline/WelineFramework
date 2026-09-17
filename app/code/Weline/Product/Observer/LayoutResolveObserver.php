<?php

declare(strict_types=1);

namespace Weline\Product\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * Claim product / category storefront paths onto shell layout_path (slug is entity only).
 */
final class LayoutResolveObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }
        if ((bool)$data->getData('claimed')) {
            return;
        }

        $requestPath = strtolower(trim(str_replace('\\', '/', (string)$data->getData('request_path')), '/'));

        if ($requestPath === 'products') {
            $data->setData('claimed', true);
            $data->setData('layout_path', 'products');
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', '');
            $data->setData('entity_kind', '');

            return;
        }

        if ($requestPath === 'product') {
            $data->setData('claimed', true);
            $data->setData('layout_path', 'product');
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', '');
            $data->setData('entity_kind', '');

            return;
        }

        if (preg_match('#^product/([a-z0-9][a-z0-9_-]*)$#D', $requestPath, $matches) === 1) {
            $data->setData('claimed', true);
            $data->setData('layout_path', 'product');
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', strtolower((string)$matches[1]));
            $data->setData('entity_kind', 'product');

            return;
        }

        if ($requestPath === 'category' || $requestPath === 'categories') {
            $data->setData('claimed', true);
            $data->setData('layout_path', 'category');
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', '');
            $data->setData('entity_kind', '');

            return;
        }

        if (preg_match('#^category/([a-z0-9][a-z0-9_/-]*)$#D', $requestPath, $matches) === 1) {
            $data->setData('claimed', true);
            $data->setData('layout_path', 'category');
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', strtolower(trim((string)$matches[1], '/')));
            $data->setData('entity_kind', 'category');
        }
    }
}
