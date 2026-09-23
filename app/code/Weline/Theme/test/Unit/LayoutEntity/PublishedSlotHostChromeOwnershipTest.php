<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost;
final class PublishedSlotHostChromeOwnershipTest extends TestCase
{
    public function testRenderedChromeOwnershipTakesPrecedenceOverSlotName(): void
    {
        $previous = Context::getCurrent();
        Context::enter(new Context());
        try {
            RequestContext::set(ThemeLayoutEntityPublishedSlotHost::CTX_FRAGMENTS,
                ['page_html' => '', 'chrome_by_slot' => ['merchant-drawer' => '<nav>Menu</nav>']]);
            $method = new \ReflectionMethod(ThemeLayoutEntityPublishedSlotHost::class, 'resolveBakeInner');
            self::assertSame('<nav>Menu</nav>', $method->invoke(null, 'merchant-drawer'));
        } finally {
            if ($previous !== null) { Context::enter($previous); }
            else { Context::leave(); }
        }
    }
}
