<?php

declare(strict_types=1);

namespace Weline\Shipping\Observer;

use Weline\Framework\Event\Event;
use Weline\Shipping\Model\EmbargoRegion;

final class StoreSaveAfter extends AbstractEmbargoSaveAfter
{
    public function execute(Event &$event): void
    {
        $this->persist($event, EmbargoRegion::SCOPE_STORE, 'store_id');
    }
}
