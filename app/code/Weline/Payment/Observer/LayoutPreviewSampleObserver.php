<?php

declare(strict_types=1);

namespace Weline\Payment\Observer;

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
        if (strtolower(trim((string)$data->getData('layout_path'))) !== 'payment_guide') {
            return;
        }
        $preferred = strtolower(trim((string)$data->getData('preferred_slug')));
        $data->setData('claimed', true);
        $data->setData(
            'preview_entity_route',
            $preferred !== '' ? 'guide/payment/' . $preferred : 'guide/payment'
        );
        $data->setData('entity_slug', $preferred);
        $data->setData('sample_source', $preferred !== '' ? 'remembered' : 'hub');
    }
}
