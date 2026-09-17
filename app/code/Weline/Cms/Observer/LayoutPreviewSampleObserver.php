<?php

declare(strict_types=1);

namespace Weline\Cms\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

final class LayoutPreviewSampleObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject || (bool)$data->getData('claimed')) {
            return;
        }
        if (strtolower(trim((string)$data->getData('layout_path'))) !== 'cms_page') {
            return;
        }
        $preferred = strtolower(trim((string)$data->getData('preferred_slug')));
        // Without a concrete published page, do not fake a storefront sample.
        if ($preferred === '') {
            $data->setData('claimed', false);
            $data->setData('preview_entity_route', '');
            $data->setData('sample_source', 'none');

            return;
        }
        $data->setData('claimed', true);
        $data->setData('preview_entity_route', 'page/' . $preferred);
        $data->setData('entity_slug', $preferred);
        $data->setData('sample_source', 'remembered');
    }
}
