<?php
declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\LayoutCriticalCssExtractor;

final class LayoutCriticalCssCompileDecision implements ObserverInterface
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
        if ($tplFile !== '' && $this->extractor->shouldForceRecompile($tplFile)) {
            $eventData->setData('force', true);
        }
    }
}
