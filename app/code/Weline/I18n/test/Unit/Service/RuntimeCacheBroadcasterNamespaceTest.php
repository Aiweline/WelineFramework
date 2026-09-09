<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\Framework\Runtime\RuntimeNamespaceInvalidationPublisherInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\I18n\Service\RuntimeCacheBroadcaster;

final class RuntimeCacheBroadcasterNamespaceTest extends TestCase
{
    public function testCommittedDictionaryVersionOnlyUsesNamespacePublication(): void
    {
        $legacy = $this->createMock(RuntimeControlBroadcasterInterface::class);
        $legacy->expects(self::never())->method('cacheClear');
        $namespace = $this->createMock(RuntimeNamespaceInvalidationPublisherInterface::class);
        $namespace->expects(self::once())->method('publish')
            ->with(19, ['global/i18n' => 7], null, self::isType('string'))
            ->willReturn(['success' => true]);
        $resolver = (new \ReflectionClass(RuntimeProviderResolver::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(RuntimeProviderResolver::class, 'resolved'))->setValue($resolver, [
            RuntimeControlBroadcasterInterface::class => $legacy,
            RuntimeNamespaceInvalidationPublisherInterface::class => $namespace,
        ]);

        (new RuntimeCacheBroadcaster($resolver))->broadcastCommitted(19, ['global/i18n' => 7]);
    }

    public function testUnavailableOptionalPublisherDoesNotFallBackToClearingBusinessCaches(): void
    {
        $legacy = $this->createMock(RuntimeControlBroadcasterInterface::class);
        $legacy->expects(self::never())->method('cacheClear');
        $resolver = (new \ReflectionClass(RuntimeProviderResolver::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(RuntimeProviderResolver::class, 'resolved'))->setValue($resolver, [
            RuntimeControlBroadcasterInterface::class => $legacy,
            RuntimeNamespaceInvalidationPublisherInterface::class => null,
        ]);

        (new RuntimeCacheBroadcaster($resolver))->broadcastCommitted(19, ['global/i18n' => 7]);
    }
}
