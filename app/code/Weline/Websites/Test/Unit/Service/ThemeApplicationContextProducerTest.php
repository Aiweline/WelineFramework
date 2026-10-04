<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Theme\Api\DefaultThemeInterface;
use Weline\Theme\Api\Version\ThemeApplicationReferenceReaderInterface;
use Weline\Websites\Api\Theme\ThemeApplicationInterface;
use Weline\Websites\Api\Theme\ThemeApplicationReference;
use Weline\Websites\Api\Theme\ThemeApplicationResolution;
use Weline\Websites\Service\ThemeApplicationContextProducer;

final class ThemeApplicationContextProducerTest extends TestCase
{
    public function testWebsiteConsumerSuppliesModeAndExactContentOwner(): void
    {
        self::assertTrue(class_exists(ThemeApplicationContextProducer::class), 'Website context producer is missing.');
        self::assertSame(realpath(dirname(__DIR__, 3) . '/Service/ThemeApplicationContextProducer.php'), (new \ReflectionClass(ThemeApplicationContextProducer::class))->getFileName());
        $reference = new ThemeApplicationReference(3, 11, 4, 'default.__website__.default', 'test', 'frontend');
        $applications = $this->createMock(ThemeApplicationInterface::class);
        $applications->expects(self::once())->method('resolve')->willReturn(new ThemeApplicationResolution($reference, 'website|0|default||||v1', true, 2));
        $reader = $this->createMock(ThemeApplicationReferenceReaderInterface::class);
        $reader->expects(self::once())->method('resourceReferences')->with($reference->toArray())->willReturn([
            'resourceA' => ['release_id' => 91, 'theme_version_id' => 11, 'content_revision' => 4, 'context' => [
                'scope' => ['provider' => 'websites', 'storage_scope' => 'default.__website__.default', 'store_mode' => 'test'], 'area' => 'frontend',
            ]],
        ]);
        $defaultTheme = $this->createMock(DefaultThemeInterface::class);
        $defaultTheme->expects(self::never())->method('defaultApplicationReference');
        $producer = new ThemeApplicationContextProducer($applications, new SystemConfigScopeResolver(), $reader, $defaultTheme);
        $claims = ['scope_identity' => ScopeIdentity::website(0, 'default')->toArray(), 'scope' => 'default.__website__.default'];
        $context = $producer->build(ScopeIdentity::website(0, 'default'), 'test', 'zh_Hans_CN', '默认网站', [], 'editor', $claims, ['storefront/site0']);
        self::assertSame('websites', $context->provider);
        self::assertSame('default.__website__.default', $context->scopeKey);
        self::assertSame('test', $context->storeMode);
        self::assertSame('test', $context->contentScopes[1]['store_mode']);
        self::assertSame('default.default.default', $context->contentScopes[1]['scope_key']);
        self::assertSame(91, $context->contentScopes[0]['resource_references']['resourceA']['release_id']);
        self::assertSame([3, 11, 4, 'default.__website__.default', 'test'], [$context->themeId, $context->themeVersionId, $context->contentRevision, $context->versionOwnerScope, $context->versionOwnerStoreMode]);
        self::assertSame($claims, $context->assetAccessClaims);
        self::assertSame(['storefront/site0'], $context->invalidationNamespaces);
    }

    public function testMissingApplicationFallsBackToThemeRegisteredDefault(): void
    {
        $applications = $this->createMock(ThemeApplicationInterface::class);
        $applications->expects(self::once())->method('resolve')->willReturn(new ThemeApplicationResolution(null, null, false, 0));
        $reader = $this->createMock(ThemeApplicationReferenceReaderInterface::class);
        $reader->expects(self::once())->method('resourceReferences')->willReturn([]);
        $defaultTheme = $this->createMock(DefaultThemeInterface::class);
        $defaultTheme->expects(self::once())->method('defaultApplicationReference')
            ->with('frontend', 'default.__website__.default', 'normal')
            ->willReturn([
                'theme_id' => 0,
                'theme_version_id' => 0,
                'content_revision' => 0,
                'owner_scope' => 'default.__website__.default',
                'store_mode' => 'normal',
                'area' => 'frontend',
                'source_kind' => 'package_defaults',
            ]);
        $producer = new ThemeApplicationContextProducer($applications, new SystemConfigScopeResolver(), $reader, $defaultTheme);
        $context = $producer->build(ScopeIdentity::website(0, 'default'), 'normal', 'zh_Hans_CN', '默认网站');
        self::assertSame(0, $context->themeId);
        self::assertSame(0, $context->themeVersionId);
        self::assertSame(0, $context->contentRevision);
        self::assertSame('default.__website__.default', $context->versionOwnerScope);
    }
}
