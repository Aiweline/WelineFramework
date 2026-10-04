<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\Api\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Theme\Service\Scoped\ThemeEditorContextFactory;
use Weline\Theme\Service\ThemeRuntimeLayoutResolver;

final class ThemeNeutralContextConsumptionTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX . 'frontend.runtime');
    }

    public function testEditorRejectsUnverifiedBrowserScopeBeforeAnyAssetLookup(): void
    {
        $factory = (new \ReflectionClass(ThemeEditorContextFactory::class))->newInstanceWithoutConstructor();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('theme_editor_consumer_context_required');
        $factory->fromInput(['scope'=>['storage_scope'=>'fake.scope.channel'], 'theme_id'=>3]);
    }

    public function testRuntimeBuildsFromFrozenNeutralContextWithoutBusinessResolver(): void
    {
        (new ThemeApplicationContext('portal', 'main', 'test', 'frontend', 3, '!external!portal.main',
            'test', 12, 4, 'en_US'))->install();
        $resolver = (new \ReflectionClass(ThemeRuntimeLayoutResolver::class))->newInstanceWithoutConstructor();
        $context = $resolver->buildContext(3, 'account/login', 'frontend', ['layout_option'=>'landing','target_type'=>'cms_page','target_id'=>42]);
        self::assertSame('!external!portal.main', $context->scope->storageScope);
        self::assertSame('test', $context->scope->storeMode);
        self::assertSame('landing', $context->layoutOption);
        self::assertSame(42, $context->targetId);
    }

    public function testRuntimeCannotBorrowFrozenContextForDifferentTheme(): void
    {
        (new ThemeApplicationContext('portal', 'main', 'test', 'frontend', 3, '!external!portal.main',
            'test', 12, 4, 'en_US'))->install();
        $resolver = (new \ReflectionClass(ThemeRuntimeLayoutResolver::class))->newInstanceWithoutConstructor();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('theme_runtime_context_theme_mismatch');
        $resolver->buildContext(9, 'homepage', 'frontend');
    }
}
