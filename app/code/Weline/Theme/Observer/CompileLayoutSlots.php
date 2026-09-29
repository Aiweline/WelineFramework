<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;

final class CompileLayoutSlots implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if ($data instanceof DataObject) {
            $data->setData('content', LayoutRelationCompiler::compileRuntimeSlots((string)$data->getData('content')));
        }
    }
}
