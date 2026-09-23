<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Framework\App\State;
use Weline\Theme\Service\LayoutEntity\EntityRenderBinding;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityChrome;
require_once dirname(__DIR__, 7) . '/vendor/autoload.php';
final class ThemeLayoutEntityBoundChromeTest extends TestCase
{
    public function testDirectChromeIncludeUsesTheExistingNestedSlotRecursionBoundary(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'theme-chrome-nested-');
        file_put_contents($path, '<?php echo \\Weline\\Framework\\Runtime\\RequestContext::get(\\Weline\\Theme\\Service\\LayoutEntity\\ThemeLayoutEntityPublishedSlotHost::CTX_SOLIDIFYING) === true ? "guarded" : "recursive";');
        $previous = \Weline\Framework\Context::getCurrent();
        \Weline\Framework\Context::enter(new \Weline\Framework\Context());
        try {
            $class = new \ReflectionClass(ThemeLayoutEntityChrome::class);
            $chrome = $class->newInstanceWithoutConstructor();
            self::assertSame('guarded', $class->getMethod('includeChromePhtml')->invoke($chrome, $path));
            self::assertNull(\Weline\Framework\Runtime\RequestContext::get(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::CTX_SOLIDIFYING));
        } finally {
            unlink($path);
            if ($previous !== null) { \Weline\Framework\Context::enter($previous); }
            else { \Weline\Framework\Context::leave(); }
        }
    }

    public function testFailedRefreshPreservesExistingSnapshot(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'theme-chrome-fail-');
        file_put_contents($path, '<?php throw new \\RuntimeException("fixture render failed");');
        $reflection = new \ReflectionClass(ThemeLayoutEntityChrome::class);
        $chrome = $reflection->newInstanceWithoutConstructor();
        $binding = new EntityRenderBinding(1, 'scope', '', 'tv1', 's1', basename($path), 'chrome', $path, '', '', '', '');
        $snapshot = $reflection->getMethod('renderedCachePath')->invoke($chrome, $path, $binding);
        file_put_contents($snapshot, '<header>last complete</header>');
        try {
            try {
                $chrome->forceResolidifyRenderedSnapshot($path, '', $binding);
                self::fail('The broken template must propagate its render error.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('fixture render failed', $error->getMessage());
            }
            self::assertSame('<header>last complete</header>', file_get_contents($snapshot));
        } finally {
            unlink($path);
            unlink($snapshot);
        }
    }

    public function testSnapshotSeparatesConfigAndLocaleWithoutModificationTime(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'theme-chrome-');
        $reflection = new \ReflectionClass(ThemeLayoutEntityChrome::class);
        $chrome = $reflection->newInstanceWithoutConstructor();
        $cachePath = $reflection->getMethod('renderedCachePath');
        $read = $reflection->getMethod('readRenderedCache');
        $previousContext = \Weline\Framework\Context::getCurrent();
        \Weline\Framework\Context::enter(new \Weline\Framework\Context());
        $oldLocale = State::getRequestLanguageOverride();
        $snapshot = '';
        try {
            $a = new EntityRenderBinding(1, 'scope', '', 'tv1', 's1', 'c1', 'chrome', $path, '', '', '', '');
            $b = new EntityRenderBinding(1, 'scope', '', 'tv1', 's1', 'c2', 'chrome', $path, '', '', '', '');
            State::setRequestLanguageOverride('en_US');
            $snapshot = $cachePath->invoke($chrome, $path, $a);
            self::assertStringContainsString('chrome.rendered.v2.', $snapshot);
            self::assertNotSame($snapshot, $cachePath->invoke($chrome, $path, $b));
            file_put_contents($snapshot, '<header>complete</header>');
            touch($snapshot, time() - 10);
            touch($path, time());
            self::assertSame('<header>complete</header>', $read->invoke($chrome, $path, $a));
            self::assertNull($read->invoke($chrome, $path, $b));
            State::setRequestLanguageOverride('zh_Hans_CN');
            self::assertNotSame($snapshot, $cachePath->invoke($chrome, $path, $a));
        } finally {
            State::setRequestLanguageOverride($oldLocale);
            if ($previousContext !== null) {
                \Weline\Framework\Context::enter($previousContext);
            } else {
                \Weline\Framework\Context::leave();
            }
            unlink($path);
            if ($snapshot !== '' && is_file($snapshot)) {
                unlink($snapshot);
            }
        }
    }
}
