<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Server\Extends\Module\Weline_Framework\Query\MemoryQueryProvider;
use Weline\Server\Extends\Module\Weline_Framework\Query\SessionQueryProvider;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Server\Service\SessionStateFacade;

final class SessionAndMemoryQueryProviderTest extends TestCase
{
    public function testSessionQueryProviderDelegatesListToFacade(): void
    {
        $facade = $this->createMock(SessionStateFacade::class);
        $facade->expects(self::once())
            ->method('list')
            ->with([
                'filter' => ['__domain' => 'session'],
                'limit' => 20,
            ])
            ->willReturn([
                ['session_id' => 'abc', 'data' => ['user_id' => 1]],
            ]);

        $provider = new SessionQueryProvider($facade);
        $result = $provider->execute('list', [
            'filter' => ['__domain' => 'session'],
            'limit' => 20,
        ]);

        self::assertCount(1, $result);
        self::assertSame('abc', $result[0]['session_id']);
    }

    public function testMemoryQueryProviderDelegatesNamespaceOperationsToFacade(): void
    {
        $facade = $this->createMock(MemoryStateFacade::class);
        $facade->expects(self::once())
            ->method('clearNamespace')
            ->with('cache:product')
            ->willReturn(true);

        $provider = new MemoryQueryProvider($facade);
        $result = $provider->execute('clearNamespace', [
            'namespace' => 'cache:product',
        ]);

        self::assertTrue($result);
    }
}
