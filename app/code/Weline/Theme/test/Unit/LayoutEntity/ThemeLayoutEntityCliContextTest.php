<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;

final class ThemeLayoutEntityCliContextTest extends TestCase
{
    public function testCliBakeUsesItsStoredVersionOwnerWithoutARequestSelection(): void
    {
        $identity = new ThemeVersionIdentity(4, 'default.__website__.default', 'normal', 'frontend', 17, 'formal', 6);
        $context = $this->context($identity, 'mini-cart', 'compact', 'category', 23);

        self::assertSame('websites', $context->scope->provider);
        self::assertSame($identity->canonicalScope, $context->scope->storageScope);
        self::assertSame('normal', $context->scope->storeMode);
        self::assertSame(['default.__website__.default'], $context->scope->fallbackStorageScopes);
        self::assertSame('mini-cart', $context->layoutType);
        self::assertSame('compact', $context->layoutOption);
        self::assertSame('category', $context->targetType);
        self::assertSame(23, $context->targetId);
        self::assertSame(17, $context->application->themeVersionId);
        self::assertSame(6, $context->application->contentRevision);
        self::assertSame($identity->canonicalScope, $context->application->versionOwnerScope);
        self::assertSame('asset', $context->application->purpose);
        self::assertNull(ThemeApplicationContext::current('frontend', 'asset'));
        self::assertNull(ThemeApplicationContext::current('frontend'));
    }

    public function testCliBakeDecodesAnExternalOwnerWithoutInventingParents(): void
    {
        $identity = new ThemeVersionIdentity(4, '!external!catalog.shop-a.section_b', 'mobile', 'backend', 9, 'draft', 3);
        $context = $this->context($identity, 'homepage', 'default', 'global', null);

        self::assertSame('catalog', $context->scope->provider);
        self::assertSame('shop-a.section_b', $context->scope->scopeKey);
        self::assertSame($identity->canonicalScope, $context->scope->storageScope);
        self::assertNull($context->scope->parent);
        self::assertSame('backend', $context->area);
        self::assertSame('mobile', $context->application->versionOwnerStoreMode);
        self::assertSame(9, $context->application->themeVersionId);
        self::assertSame(3, $context->application->contentRevision);
        self::assertSame(0, $context->targetId);
    }

    private function context(ThemeVersionIdentity $identity, string $type, string $option, string $target, ?int $targetId): \Weline\Theme\Api\Scoped\ThemeEditorContext
    {
        $reflection = new \ReflectionClass(ThemeLayoutEntityBakeCoordinator::class);
        self::assertStringContainsString('/app/code/Weline/Theme/', $reflection->getFileName());
        $coordinator = $reflection->newInstanceWithoutConstructor();
        // 复用真实请求解析器的上下文检查，排除其构造时的数据库依赖。
        $resolverClass = \Weline\Theme\Service\ThemeRuntimeLayoutResolver::class;
        $previous = \Weline\Framework\Manager\ObjectManager::_getInstance($resolverClass);
        $resolver = (new \ReflectionClass($resolverClass))->newInstanceWithoutConstructor();
        \Weline\Framework\Manager\ObjectManager::setInstance($resolverClass, $resolver);
        try {
            return $reflection->getMethod('context')->invoke($coordinator, $identity, $type, $option, $target, $targetId);
        } finally {
            \Weline\Framework\Manager\ObjectManager::removeInstance($resolverClass);
            if ($previous !== null) { \Weline\Framework\Manager\ObjectManager::setInstance($resolverClass, $previous); }
        }
    }
}
