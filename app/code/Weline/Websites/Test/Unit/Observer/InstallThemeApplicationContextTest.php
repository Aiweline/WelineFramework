<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Theme\Api\DefaultThemeInterface;
use Weline\Theme\Api\Version\ThemeApplicationReferenceReaderInterface;
use Weline\Websites\Api\Theme\ThemeApplicationInterface;
use Weline\Websites\Api\Theme\ThemeApplicationReference;
use Weline\Websites\Api\Theme\ThemeApplicationResolution;
use Weline\Websites\Model\Website;
use Weline\Websites\Observer\InstallThemeApplicationContext;
use Weline\Websites\Service\ThemeApplicationContextProducer;

final class InstallThemeApplicationContextTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX . 'frontend.runtime');
    }

    public function testRuntimeConsumerUsesFrozenScopeAndDoesNotClaimBackend(): void
    {
        self::assertTrue(class_exists(InstallThemeApplicationContext::class), 'Website runtime context observer is missing.');
        $applications = $this->createStub(ThemeApplicationInterface::class);
        $applications->method('resolve')->willReturn(new ThemeApplicationResolution(new ThemeApplicationReference(3, 11, 4, 'default.__website__.default', 'normal', 'frontend'), 'website|0|default||||v1', false, 0));
        $website = $this->createStub(Website::class);
        $website->method('load')->willReturnSelf();
        $website->method('getId')->willReturn(0);
        $website->method('hasData')->willReturn(true);
        $website->method('getDefaultLanguage')->willReturn('zh_Hans_CN');
        $website->method('getName')->willReturn('默认网站');
        $reader = $this->createStub(ThemeApplicationReferenceReaderInterface::class);
        $reader->method('resourceReferences')->willReturn([]);
        $defaultTheme = $this->createStub(DefaultThemeInterface::class);
        $observer = new InstallThemeApplicationContext(
            new ThemeApplicationContextProducer($applications, new SystemConfigScopeResolver(), $reader, $defaultTheme),
            $website,
        );
        $backend = new Event(['area' => 'backend', 'scope_identity' => null]);
        $observer->execute($backend);
        self::assertNull(ThemeApplicationContext::current('frontend'));
        $event = new Event(['area' => 'frontend', 'scope_identity' => ScopeIdentity::channel(0, 'default', 'main', 'web', 'normal')]);
        $observer->execute($event);
        $context = ThemeApplicationContext::current('frontend');
        self::assertSame('default.main.web', $context->scopeKey);
        self::assertSame('runtime', $context->purpose);
        self::assertSame(3, $context->themeId);
        self::assertSame(11, $context->themeVersionId);
        self::assertNull(ThemeApplicationContext::current('frontend', 'editor'));
    }
}
