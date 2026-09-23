<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
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

    public function testDraftFallbackNeverSelectsAnUnrelatedReleaseDirectory(): void
    {
        if (!defined('BP')) { define('BP', dirname(__DIR__, 7)); }
        $paths = new ThemeLayoutEntityPaths();
        $scope = 'test-read-binding-' . bin2hex(random_bytes(6));
        $base = $paths->pageIdentityDir(999999986, $scope, 'fixture');
        mkdir($base . 'r999', 0775, true);
        file_put_contents($base . 'r999/layout.phtml', '<!--@weline-slot:content-->old release');
        try {
            $class = new \ReflectionClass(ThemeLayoutEntitySlotFiller::class);
            $filler = $class->newInstanceWithoutConstructor();
            $class->getProperty('paths')->setValue($filler, $paths);
            self::assertNull($class->getMethod('scanSolidifiedSegment')->invoke($filler, 999999986, $scope, 'fixture', false));
        } finally {
            unlink($base . 'r999/layout.phtml');
            rmdir($base . 'r999');
            rmdir($base);
            rmdir(dirname($base));
            rmdir(dirname($base, 2));
        }
    }
}
