<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\WlsManagedDomainActiveEventDispatcher;

final class WlsManagedDomainActiveEventDispatcherTest extends TestCase
{
    public function testEventNameContract(): void
    {
        self::assertSame(
            'Weline_Server::domain::managed_domain_active',
            WlsManagedDomainActiveEventDispatcher::EVENT_NAME,
        );
    }
}
