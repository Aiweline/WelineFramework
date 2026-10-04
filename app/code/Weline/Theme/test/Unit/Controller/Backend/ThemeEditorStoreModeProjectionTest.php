<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Controller\Backend\ThemeEditor;

final class ThemeEditorStoreModeProjectionTest extends TestCase
{
    private function context(): ThemeEditorContext
    {
        return new ThemeEditorContext(new ScopeContext(
            ScopeIdentity::channel(7, 'shop', 'main', 'app', 'test'),
            'shop.main.app', 'test', ['shop.main.app']), 'frontend', 'layout', 1, 'account/login', 'default');
    }

    public function testPreviewLayoutProjectionKeepsExplicitStoreMode(): void
    {
        $controller = (new \ReflectionClass(ThemeEditor::class))->newInstanceWithoutConstructor();
        $identity = (new \ReflectionMethod($controller, 'layoutIdentityFromEditorContext'))->invoke($controller, $this->context());
        self::assertSame('test', $identity['store_mode'] ?? null);
        self::assertSame('shop.main.app~test', $identity['scope']);
    }

    public function testCanonicalScopeClaimMatchesItsTypedModeIdentity(): void
    {
        $controller = (new \ReflectionClass(ThemeEditor::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'assertRawLayoutContextMatches');
        $method->invoke($controller, ['scope' => 'shop.main.app'], $this->context());
        $method->invoke($controller, ['scope' => 'shop.main.app~test'], $this->context());
        self::assertTrue(true);
        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($controller, ['scope' => 'shop.other.app'], $this->context());
    }
}
