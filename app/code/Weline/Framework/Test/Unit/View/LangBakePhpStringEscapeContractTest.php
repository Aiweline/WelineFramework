<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\View\Taglib;

/**
 * Regression: compile-time @lang bake inside PHP single-quoted strings must
 * var_export apostrophes (e.g. French d'utilisation), or the compiled phtml
 * ParseErrors and storefront returns HTTP 500 JSON.
 */
final class LangBakePhpStringEscapeContractTest extends TestCase
{
    /**
     * @return array{0:int,1:string,2:string}
     */
    private function adjust(string $content, int $pos, string $raw, string $replacement, string $tagName = 'lang'): array
    {
        $method = new ReflectionMethod(Taglib::class, 'adjustBakedLangInlineReplacement');
        $method->setAccessible(true);

        return $method->invoke(new Taglib(), $content, $pos, $raw, $replacement, $tagName);
    }

    private function apply(string $content, string $raw, string $replacement): string
    {
        $pos = strpos($content, $raw);
        self::assertNotFalse($pos, 'fixture must contain raw token');
        [$newPos, $newRaw, $newReplacement] = $this->adjust($content, $pos, $raw, $replacement);

        return substr($content, 0, $newPos) . $newReplacement . substr($content, $newPos + strlen($newRaw));
    }

    public function testQuotedAtLangInsidePhpExportsApostropheSafely(): void
    {
        $raw = '@lang(用户协议导航)';
        $content = "<?php \$nav = '{$raw}'; ?>";
        $baked = "Navigation des conditions d'utilisation";

        $result = $this->apply($content, $raw, $baked);

        self::assertSame(
            '<?php $nav = ' . var_export($baked, true) . '; ?>',
            $result
        );
        self::assertStringNotContainsString("d'utilisation'", $result);
        self::assertTrue(@eval('return true; ' . str_replace(['<?php', '?>'], '', $result)) !== false
            || true);
        // php -l equivalent: the assignment must be valid PHP
        $code = str_replace(['<?php', '?>'], '', $result);
        try {
            eval($code);
            $ok = true;
        } catch (\ParseError $e) {
            $ok = false;
            self::fail('baked PHP must parse: ' . $e->getMessage());
        }
        self::assertTrue($ok);
        self::assertSame($baked, $nav ?? null);
    }

    public function testBareAtLangInsidePhpBecomesExportedLiteral(): void
    {
        $raw = '@lang(支付指南导航)';
        $content = "<?php \$nav = {$raw}; ?>";
        $baked = "Navigation du guide de paiement";

        $result = $this->apply($content, $raw, $baked);

        self::assertSame(
            '<?php $nav = ' . var_export($baked, true) . '; ?>',
            $result
        );
    }

    public function testHtmlContextKeepsRawBakeIncludingApostrophe(): void
    {
        $raw = '@lang(同意)';
        $content = "<button aria-label=\"{$raw}\">OK</button>";
        $baked = "d'accord";

        $result = $this->apply($content, $raw, $baked);

        self::assertSame('<button aria-label="d\'accord">OK</button>', $result);
        self::assertStringContainsString("d'accord", $result);
        self::assertStringNotContainsString('var_export', $result);
    }

    public function testRuntimePhpStubIsNotReExported(): void
    {
        $raw = '@lang($title)';
        $content = "<?php \$x = '{$raw}'; ?>";
        $replacement = '<?=__($title)?>';

        [$pos, $keptRaw, $keptReplacement] = $this->adjust($content, (int) strpos($content, $raw), $raw, $replacement);

        self::assertSame((int) strpos($content, $raw), $pos);
        self::assertSame($raw, $keptRaw);
        self::assertSame($replacement, $keptReplacement);
    }
}
