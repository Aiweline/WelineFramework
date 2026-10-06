<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Theme\Service\ThemeRuntimeLayoutResolver;

final class ThemeRuntimeLayoutStoreModeTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['frontend.runtime', 'frontend.asset'] as $suffix) {
            RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX . $suffix);
        }
    }

    public function testExplicitStoreModeBuildsSystemContextWithoutRequestInstall(): void
    {
        $runtime = (new \ReflectionClass(ThemeRuntimeLayoutResolver::class))->newInstanceWithoutConstructor();
        foreach (['test', 'dev'] as $mode) {
            $context = $runtime->buildContext(1, 'account/login', 'frontend', [
                'scope' => 'shop.main.app',
                'store_mode' => $mode,
            ]);
            self::assertSame($mode, $context->scope->storeMode);
            self::assertSame('shop.main.app', $context->scope->storageScope);
            self::assertSame(1, $context->application->themeId);
            self::assertSame('asset', $context->application->purpose);
            self::assertNull(ThemeApplicationContext::current('frontend'));
            self::assertNull(ThemeApplicationContext::current('frontend', 'asset'));
        }
    }

    public function testEmptyIdentityWithoutConsumerContextStillFails(): void
    {
        $runtime = (new \ReflectionClass(ThemeRuntimeLayoutResolver::class))->newInstanceWithoutConstructor();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('theme_runtime_consumer_context_required');
        $runtime->buildContext(1, 'cart', 'frontend', []);
    }

    public function testEncodedScopeModeSuffixReachesStoreMode(): void
    {
        $runtime = (new \ReflectionClass(ThemeRuntimeLayoutResolver::class))->newInstanceWithoutConstructor();
        $context = $runtime->buildContext(2, 'cart', 'frontend', [
            'scope' => 'default.__store__.__channel__~test',
        ]);
        self::assertSame('test', $context->scope->storeMode);
        self::assertSame('default.__store__.__channel__', $context->scope->storageScope);
        self::assertSame(2, $context->themeId);
    }
}
