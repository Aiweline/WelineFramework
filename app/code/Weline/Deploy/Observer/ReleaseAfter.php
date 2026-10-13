<?php

declare(strict_types=1);

namespace Weline\Deploy\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 发布完成后收口钩子。
 *
 * 系统正式发布：零碰店面 FPC、零写主题静态戳。
 * 静态戳与 FPC 由主题版本发布（scope+theme+version）与业务 ChangedType 自管。
 */
class ReleaseAfter implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        unset($event);
        // Intentionally empty: system release must not thrash FPC or theme static stamps.
    }
}
