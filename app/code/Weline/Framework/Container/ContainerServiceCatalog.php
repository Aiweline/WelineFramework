<?php

declare(strict_types=1);

namespace Weline\Framework\Container;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ServiceScope;
use Weline\Framework\Runtime\RequestScope;

/**
 * Explicit Phase-1 service boundary for the compiled container.
 *
 * The catalog is intentionally small. Adding a service is an architectural
 * lifecycle decision, not a directory scan side effect.
 */
final class ContainerServiceCatalog
{
    /**
     * @return array<string, array{class:class-string, scope:ServiceScope}>
     */
    public function definitions(): array
    {
        return [
            ServiceProviderRegistry::class => [
                'class' => ServiceProviderRegistry::class,
                'scope' => ServiceScope::PROCESS,
            ],
            Response::class => [
                'class' => Response::class,
                'scope' => ServiceScope::REQUEST,
            ],
            RequestScope::class => [
                'class' => RequestScope::class,
                'scope' => ServiceScope::FIBER,
            ],
            DataObject::class => [
                'class' => DataObject::class,
                'scope' => ServiceScope::PROTOTYPE,
            ],
        ];
    }
}
