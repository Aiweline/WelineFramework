<?php

declare(strict_types=1);

namespace Weline\Cms\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

final class LayoutResolveObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject || (bool)$data->getData('claimed')) {
            return;
        }
        $requestPath = strtolower(trim(str_replace('\\', '/', (string)$data->getData('request_path')), '/'));
        if ($requestPath === 'cms' || preg_match('#^cms/([a-z0-9_-]+)$#D', $requestPath, $m) === 1
            || preg_match('#^page/([a-z0-9_-]+)$#D', $requestPath, $m) === 1) {
            $data->setData('claimed', true);
            $data->setData('layout_path', 'cms_page');
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', isset($m[1]) ? strtolower((string)$m[1]) : '');
            $data->setData('entity_kind', isset($m[1]) ? 'cms_page' : '');
        }
    }
}
