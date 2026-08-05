<?php

declare(strict_types=1);

namespace Weline\Framework\Api\Event;

use Weline\Framework\Event\ObserverInterface;

interface AsyncObserverInterface extends ObserverInterface
{
    public function supportsAsyncEvent(string $eventName, int $schemaVersion): bool;
}
