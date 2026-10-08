<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Scoped\ThemeContentScope;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Controller\Backend\ThemeEditor;

final class ThemeEditorStoreModeProjectionTest extends TestCase
{
    private function context(string $storageScope = 'shop.main.app', string $storeMode = 'test'): ThemeEditorContext
    {
        $scope = new ThemeContentScope(
            provider: 'websites',
            scopeKey: $storageScope,
            storeMode: $storeMode,
            displayName: 'Shop',
            defaultLocale: 'zh_Hans_CN',
        );

        return new ThemeEditorContext(
            $scope,
            'frontend',
            ThemeEditorContext::RESOURCE_LAYOUT,
            1,
            'account/login',
            'default',
        );
    }

    public function testPreviewLayoutProjectionKeepsExplicitStoreMode(): void
    {
        $controller = (new \ReflectionClass(ThemeEditor::class))->newInstanceWithoutConstructor();
        $identity = (new \ReflectionMethod($controller, 'layoutIdentityFromEditorContext'))
            ->invoke($controller, $this->context());
        self::assertSame('test', $identity['store_mode'] ?? null);
        self::assertSame('shop.main.app~test', $identity['scope']);
    }

    public function testCanonicalScopeClaimMatchesItsTypedModeIdentity(): void
    {
        $controller = (new \ReflectionClass(ThemeEditor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'assertRawLayoutContextMatches');
        $method->setAccessible(true);
        $ctx = $this->context();
        $method->invoke($controller, ['scope' => 'shop.main.app'], $ctx);
        $method->invoke($controller, ['scope' => 'shop.main.app~test'], $ctx);
        // URL / Host-only shorthand: bare website_code matching storage first segment.
        $method->invoke($controller, ['scope' => 'shop'], $ctx);
        self::assertTrue(true);
        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($controller, ['scope' => 'shop.other.app'], $ctx);
    }

    public function testBareWebsiteCodeShorthandRejectedWhenMismatch(): void
    {
        $controller = (new \ReflectionClass(ThemeEditor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'assertRawLayoutContextMatches');
        $method->setAccessible(true);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('theme_editor_raw_context_mismatch:scope');
        $method->invoke($controller, ['scope' => 'grocery'], $this->context());
    }

    public function testGroceryWebsiteShorthandMatchesStorageScope(): void
    {
        $controller = (new \ReflectionClass(ThemeEditor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'assertRawLayoutContextMatches');
        $method->setAccessible(true);
        $ctx = $this->context('grocery.default.default', 'normal');
        $method->invoke($controller, ['scope' => 'grocery'], $ctx);
        $method->invoke($controller, ['scope' => 'grocery.default.default'], $ctx);
        self::assertTrue(true);
    }
}
