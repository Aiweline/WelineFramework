<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\LayoutStorefrontRouteFromModuleRouter;

/**
 * Last-resort layout_preview_sample: catalog module_name + Env::getModuleInfo router join.
 * Runs after business slug observers (sort 900).
 */
final class LayoutPreviewSampleObserver implements ObserverInterface
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

        $layoutPath = strtolower(trim(str_replace('\\', '/', (string)$data->getData('layout_path')), '/'));
        $layoutOption = strtolower(trim((string)$data->getData('layout_option')));
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }

        try {
            /** @var LayoutStorefrontRouteFromModuleRouter $resolver */
            $resolver = ObjectManager::getInstance(LayoutStorefrontRouteFromModuleRouter::class);
            $route = $resolver->resolve($layoutPath, $layoutOption);
        } catch (\Throwable) {
            return;
        }

        $isHomepage = $layoutPath === ''
            || $layoutPath === 'homepage'
            || $layoutPath === 'default'
            || $layoutPath === 'index'
            || $layoutPath === 'index/index';
        if ($route === '' && !$isHomepage) {
            return;
        }

        $data->setData('claimed', true);
        $data->setData('preview_entity_route', $route);
        $data->setData('entity_slug', '');
        $data->setData('sample_source', 'module_router');
    }
}
