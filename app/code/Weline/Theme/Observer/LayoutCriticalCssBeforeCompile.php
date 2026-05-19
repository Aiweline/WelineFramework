<?php
declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\LayoutCriticalCssExtractor;

final class LayoutCriticalCssBeforeCompile implements ObserverInterface
{
    public function __construct(
        private LayoutCriticalCssExtractor $extractor,
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventData = $event->getData('data');
        if (!$eventData instanceof DataObject) {
            return;
        }

        $tplFile = (string)$eventData->getData('tplFile');
        if ($tplFile === '' || !$this->extractor->shouldHandleSource($tplFile)) {
            return;
        }

        $content = (string)$eventData->getData('content');
        $eventData->setData('content', $this->extractor->extractAndPersist($content, $tplFile));
    }
}
