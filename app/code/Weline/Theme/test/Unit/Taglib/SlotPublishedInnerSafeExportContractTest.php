<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Theme\Taglib\Slot;

/**
 * wave8-8s hotfix: publishedInner must never embed compiled defaults in a
 * single-quoted PHP source literal (UTF-8 / nested quotes → ParseError).
 */
final class SlotPublishedInnerSafeExportContractTest extends TestCore
{
    public function setUp(): void
    {
        parent::setUp();
        Slot::clearRegisteredSlots();
    }

    public function tearDown(): void
    {
        Slot::clearRegisteredSlots();
        parent::tearDown();
    }

    public function testCompiledSlotWithUtf8QuotesAndPhpDoesNotBreakPhpLint(): void
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $source = <<<'PHTML'
<w:slot id="logo" name="Logo" exclusive="true" class="header-logo">
    <a href="/"
       aria-label="<?= htmlspecialchars($logoText, ENT_QUOTES, 'UTF-8') ?>"
       title="it's a logo">
        <?php if ($logoFileHtml !== ''): ?>
            <span class="logo-image"><?= $logoFileHtml ?></span>
        <?php endif; ?>
    </a>
</w:slot>
PHTML;

        $compiled = $taglib->compile(
            $template,
            $source,
            'slot-published-inner-safe-' . \uniqid('', true) . '.phtml',
        );

        self::assertStringContainsString('ThemeLayoutEntityPublishedSlotHost::publishedInner', $compiled);
        self::assertStringContainsString('FiberOutputBuffer::beginCapture()', $compiled);
        self::assertStringContainsString('FiberOutputBuffer::endCapture()', $compiled);
        self::assertStringContainsString('FiberOutputBuffer::discardCapture()', $compiled);
        self::assertStringNotContainsString('ob_start()', $compiled);
        self::assertStringNotContainsString('ob_get_clean()', $compiled);
        // Must not embed body as publishedInner('logo', '<a...UTF-8...') source literal.
        self::assertDoesNotMatchRegularExpression(
            "/publishedInner\\('logo',\\s*'\\s*<a/s",
            $compiled,
        );
        self::assertDoesNotMatchRegularExpression(
            "/publishedInner\\('logo',\\s*'[^']*UTF-8/s",
            $compiled,
        );

        $tmp = \tempnam(\sys_get_temp_dir(), 'slot_safe_');
        self::assertNotFalse($tmp);
        $phpFile = $tmp . '.phtml';
        @\unlink($tmp);
        // Wrap so php -l accepts template fragments with HTML outside PHP tags.
        \file_put_contents($phpFile, "<?php\nreturn true;\n?>\n" . $compiled);
        \exec('php -l ' . \escapeshellarg($phpFile) . ' 2>&1', $out, $code);
        @\unlink($phpFile);
        self::assertSame(0, $code, \implode("\n", $out));
    }

    public function testRealHeaderAndHomepageSourcesCompileWithoutParseError(): void
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $roots = [
            \dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml',
            \dirname(__DIR__, 3) . '/view/theme/frontend/layouts/homepage/default.phtml',
        ];
        foreach ($roots as $path) {
            self::assertFileExists($path);
            Slot::clearRegisteredSlots();
            $src = (string)\file_get_contents($path);
            $compiled = $taglib->compile($template, $src, \basename($path));
            self::assertStringNotContainsString("publishedInner('logo', '", $compiled);
            self::assertDoesNotMatchRegularExpression(
                "/publishedInner\\('[^']+',\\s*'\\s*</s",
                $compiled,
            );
            $tmp = \tempnam(\sys_get_temp_dir(), 'slot_hdr_');
            self::assertNotFalse($tmp);
            $phpFile = $tmp . '.phtml';
            @\unlink($tmp);
            \file_put_contents($phpFile, $compiled);
            \exec('php -l ' . \escapeshellarg($phpFile) . ' 2>&1', $out, $code);
            @\unlink($phpFile);
            self::assertSame(0, $code, \basename($path) . ': ' . \implode("\n", $out));
        }
    }

    public function testSlotSourceForbidsVarExportOfNonEmptyPublishedBody(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Taglib/Slot.php');
        self::assertStringContainsString('never embed compiled default', $src);
        self::assertStringContainsString('FiberOutputBuffer::beginCapture()', $src);
        self::assertStringContainsString('FiberOutputBuffer::endCapture()', $src);
        self::assertStringContainsString('FiberOutputBuffer::discardCapture()', $src);
        // Align FormFiberCaptureContractTest: compiled product must not emit bare process-global ob.
        self::assertDoesNotMatchRegularExpression('/\\\\ob_start\\(\\)/', $src);
        self::assertDoesNotMatchRegularExpression('/\\\\ob_get_clean\\(\\)/', $src);
        // Empty body may still var_export(''); non-empty must not concatenate $contentExport.
        self::assertStringNotContainsString('$contentExport = \\var_export((string)$content, true)', $src);
    }
}
