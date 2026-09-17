<?php

declare(strict_types=1);

namespace Weline\Promotion\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * Claim promotion storefront paths: hub + activity theme slugs share layout_path=promotion.
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
        if ($requestPath === 'promotion') {
            $data->setData('claimed', true);
            $data->setData('layout_path', 'promotion');
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', '');
            $data->setData('entity_kind', '');

            return;
        }

        if (preg_match('#^promotion/([a-z0-9_-]+)$#D', $requestPath, $matches) !== 1) {
            return;
        }

        $slug = strtolower((string)$matches[1]);
        if ($slug === 'index') {
            $data->setData('claimed', true);
            $data->setData('layout_path', 'promotion');
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', '');
            $data->setData('entity_kind', '');

            return;
        }

        $data->setData('claimed', true);
        $data->setData('layout_path', 'promotion');
        $data->setData('layout_option', 'default');
        $data->setData('entity_slug', $slug);
        $data->setData('entity_kind', 'promotion_theme');
    }
}
