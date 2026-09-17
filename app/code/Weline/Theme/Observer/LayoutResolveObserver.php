<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * Last-resort layout_resolve: public path ↔ Theme layout file 1:1.
 * Modules claim first; option defaults to "default" when the path is a single segment.
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
        if ($requestPath === ''
            || str_contains($requestPath, '..')
            || str_contains($requestPath, '.')
        ) {
            return;
        }

        $layoutsRoot = dirname(__DIR__) . '/view/theme/frontend/layouts/';
        if (!is_dir($layoutsRoot)) {
            return;
        }

        if (!str_contains($requestPath, '/')) {
            if (!is_file($layoutsRoot . $requestPath . '/default.phtml')) {
                return;
            }
            $data->setData('claimed', true);
            $data->setData('layout_path', $requestPath);
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', '');
            $data->setData('entity_kind', '');

            return;
        }

        $parts = explode('/', $requestPath);
        if (count($parts) !== 2) {
            return;
        }
        [$layoutPath, $layoutOption] = $parts;
        if ($layoutPath === '' || $layoutOption === ''
            || !preg_match('/^[a-z][a-z0-9_-]*$/', $layoutPath)
            || !preg_match('/^[a-z][a-z0-9_-]*$/', $layoutOption)
        ) {
            return;
        }
        if (!is_file($layoutsRoot . $layoutPath . '/' . $layoutOption . '.phtml')) {
            return;
        }

        $data->setData('claimed', true);
        $data->setData('layout_path', $layoutPath);
        $data->setData('layout_option', $layoutOption);
        $data->setData('entity_slug', '');
        $data->setData('entity_kind', '');
    }
}
