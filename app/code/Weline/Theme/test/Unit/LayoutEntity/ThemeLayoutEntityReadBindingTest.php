<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller;
require_once dirname(__DIR__, 7) . '/vendor/autoload.php';
final class ThemeLayoutEntityReadBindingTest extends TestCase
{
    public function testEntityIdentityPreservesTheInstalledPreviewTarget(): void
    {
        $previous = \Weline\Framework\Context::getCurrent();
        \Weline\Framework\Context::enter(new \Weline\Framework\Context());
        try {
            $identity = new \Weline\Theme\Api\Layout\LayoutIdentity('campaign', 'editor.scope', 'product', 42, 'en_US');
            \Weline\Framework\Runtime\RequestContext::set($identity::REQUEST_CONTEXT_KEY, $identity);
            $class = new \ReflectionClass(ThemeLayoutEntitySlotFiller::class);
            $filler = $class->newInstanceWithoutConstructor();
            $resolved = $class->getMethod('entityIdentityForScope')->invoke($filler, 'parent.scope');
            self::assertSame('campaign', $resolved['layout_option']);
            self::assertSame('product', $resolved['target_type']);
            self::assertSame(42, $resolved['target_id']);
            self::assertSame('parent.scope', $resolved['scope']);
            self::assertSame('editor.scope', $class->getMethod('resolveScope')->invoke($filler));
        } finally {
            if ($previous !== null) { \Weline\Framework\Context::enter($previous); }
            else { \Weline\Framework\Context::leave(); }
        }
    }

    public function testSlotFillerHasNoLegacySegmentFishing(): void
    {
        $fillerSrc = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php'
        );
        $pathsSrc = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php'
        );
        self::assertStringNotContainsString('function scanSolidifiedSegment(', $fillerSrc);
        self::assertStringNotContainsString('function readPageCurrent(', $fillerSrc);
        self::assertStringNotContainsString('function rememberPageCurrent(', $fillerSrc);
        self::assertStringNotContainsString('pageCurrentJson', $pathsSrc);
        self::assertStringNotContainsString('pageStructureOrRelease', $pathsSrc);
    }
}
