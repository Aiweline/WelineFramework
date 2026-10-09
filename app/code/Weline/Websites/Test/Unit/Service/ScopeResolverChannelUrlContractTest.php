<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ScopeResolverChannelUrlContractTest extends TestCase
{
    public function testResolveChannelMatchesRemainderPathAndRelaxesL2UsablePredicate(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/ScopeResolver.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('[$channel, $routePath] = $this->resolveChannel(', $source);
        self::assertStringContainsString('channelUrlReader()->urlsByStore', $source);
        self::assertStringContainsString('channel_url_ambiguous', $source);
        self::assertStringContainsString('channel_url_invalid', $source);
        self::assertStringContainsString('freezeCurrentFromRequestContext', $source);

        self::assertMatchesRegularExpression(
            '/function isUsableCachedChannel\([\s\S]*?parentStoreLifecycleStatus === Store::LIFECYCLE_ACTIVE;\s*}/',
            $source,
        );
        self::assertDoesNotMatchRegularExpression(
            '/function isUsableCachedChannel\([\s\S]*?\$channel->isDefault\s*&&/',
            $source,
        );
    }

    public function testScopeSwitcherHidesWhenOnlyDefaultStoreAndChannel(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/ScopeSwitcherPresenter.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('$nonDefaultCount > 0', $source);
        self::assertStringContainsString('$navigableCount > 0', $source);
        self::assertStringContainsString('channelUrls->urlsByStore', $source);
    }
}
