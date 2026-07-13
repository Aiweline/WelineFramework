<?php

declare(strict_types=1);

namespace Weline\Framework\Manager;

use Weline\Framework\Container\ContainerRuntime;

/**
 * ObjectManager migration bridge. New Framework code may inject the compiled
 * ContainerInterface without teaching ObjectManager about container internals.
 */
final class ContainerInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ContainerInterface
    {
        return ContainerRuntime::get();
    }
}
