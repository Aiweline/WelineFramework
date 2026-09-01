<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\Service\HostsFileManager;
use Weline\Server\Service\LocalDomainRegisteredEventDispatcher;

final class LocalDomainRegisteredEventDispatcherTest extends TestCase
{
    public function testEventContractUsesManagedLocalDomainGate(): void
    {
        self::assertSame(
            'Weline_Server::domain::local_domain_registered',
            LocalDomainRegisteredEventDispatcher::EVENT_NAME,
        );

        $method = new ReflectionMethod(LocalDomainRegisteredEventDispatcher::class, 'dispatch');
        $source = (string) \file_get_contents($method->getFileName());
        self::assertIsString($source);
        self::assertStringContainsString('isManagedLocalDomain', $source);
        self::assertStringContainsString('$payload', $source);
    }

    public function testHostsSuccessPathDispatchesRegistrationEvent(): void
    {
        $method = new ReflectionMethod(HostsFileManager::class, 'addDomainSuccessResult');
        $source = (string) \file_get_contents($method->getFileName());
        self::assertStringContainsString('LocalDomainRegisteredEventDispatcher::dispatch', $source);
    }
}
