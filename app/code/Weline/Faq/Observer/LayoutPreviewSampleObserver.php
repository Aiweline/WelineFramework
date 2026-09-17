<?php

declare(strict_types=1);

namespace Weline\Faq\Observer;

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
        if (strtolower(trim((string)$data->getData('layout_path'))) !== 'faq') {
            return;
        }
        $preferred = strtolower(trim((string)$data->getData('preferred_slug')));
        $data->setData('claimed', true);
        $data->setData('preview_entity_route', $preferred !== '' ? 'faq/' . $preferred : 'faq');
        $data->setData('entity_slug', $preferred);
        $data->setData('sample_source', $preferred !== '' ? 'remembered' : 'hub');
    }
}
