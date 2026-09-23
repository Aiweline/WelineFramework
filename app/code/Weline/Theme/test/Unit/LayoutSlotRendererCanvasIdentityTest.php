<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit;

// The real typed context factory needs module DI bindings even when this test
// runs in a --no-configuration suite (TestCore only loads bootstrap_phpunit).
require_once dirname(__DIR__, 6) . '/app/bootstrap.php';

use Weline\Framework\Context;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Test\TestCore;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Observer\LayoutSlotRenderer;
use Weline\Theme\Service\ThemePageTypeResolver;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class LayoutSlotRendererCanvasIdentityTest extends TestCore
{
    public function testCanvasWithoutTypedQueryUsesRequestScopeOverInheritedLayout(): void
    {
        $this->assertCanvasScope(['editor_mode' => '1', 'theme_id' => 3], 'default.__store__.__channel__');
    }

    public function testExplicitCanvasContextOverridesInstalledLayout(): void
    {
        $this->assertCanvasScope(['editor_mode' => '1', 'editor_context' => [
            'scope' => ['identity' => ScopeIdentity::channel(0, 'default', 'default', 'default', 'normal')->toArray()],
            'area' => 'frontend', 'resource_type' => 'layout', 'theme_id' => 3,
            'layout_type' => 'product', 'layout_option' => 'default', 'locale' => 'default',
            'target_type' => 'global', 'target_id' => 0,
        ]], 'default.__store__.__channel__');
    }

    public function testOrdinaryStorefrontKeepsInstalledLayoutIdentity(): void
    {
        $this->assertCanvasScope(['theme_id' => 3], 'default.default.default');
    }

    private function assertCanvasScope(array $params, string $expected): void
    {
        $previousContext = Context::getCurrent();
        Context::enter(new Context());
        try {
            RequestContext::installScopeIdentity(ScopeIdentity::channel(0, 'default', 'default', 'default', 'normal'));
            RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY, new LayoutIdentity('default', 'default.default.default'));
            $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()->onlyMethods(['getParam'])->getMock();
            $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $params[$key] ?? $default);
            $class = new \ReflectionClass(LayoutSlotRenderer::class);
            $renderer = $class->newInstanceWithoutConstructor();
            $class->getProperty('request')->setValue($renderer, $request);
            $class->getProperty('pageTypeResolver')->setValue($renderer, ObjectManager::getInstance(ThemePageTypeResolver::class));
            $class->getMethod('bootstrapEditorCanvasIdentity')->invoke($renderer, '/layouts/product/default.phtml');
            self::assertSame($expected, RequestContext::get(LayoutIdentity::REQUEST_CONTEXT_KEY)->scope);
        } finally {
            $previousContext !== null ? Context::enter($previousContext) : Context::leave();
        }
    }
}
