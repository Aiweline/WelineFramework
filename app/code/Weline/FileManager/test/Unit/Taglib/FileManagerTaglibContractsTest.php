<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Taglib\Image;
use Weline\Framework\Taglib\StaticMirrorCapableInterface;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllFileManagerTaglibContractsPass().
 */
final class FileManagerTaglibContractsTest extends TestCase
{
    public function testAllFileManagerTaglibContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'FileImageStaticMirror::testImplementsStaticMirrorCapable' => function () {
            $this->assert_FileImageStaticMirror_testImplementsStaticMirrorCapable();
        },
            'FileImageStaticMirror::testDynamicUsageFallsThroughToPhpEmit' => function () {
            $this->assert_FileImageStaticMirror_testDynamicUsageFallsThroughToPhpEmit();
        },
            'FileImageStaticMirror::testLiteralWithoutLayoutDoesNotMirror' => function () {
            $this->assert_FileImageStaticMirror_testLiteralWithoutLayoutDoesNotMirror();
        },
            'FileImageStaticMirror::testTryStaticMirrorReturnsNullWhenRequestContextMissing' => function () {
            $this->assert_FileImageStaticMirror_testTryStaticMirrorReturnsNullWhenRequestContextMissing();
        },
        ];
    }

private function assert_FileImageStaticMirror_testImplementsStaticMirrorCapable(): void
{
    self::assertTrue(is_a(Image::class, StaticMirrorCapableInterface::class, true));
}

private function assert_FileImageStaticMirror_testDynamicUsageFallsThroughToPhpEmit(): void
{
    $callback = Image::callback();
    $result = $callback('tag-self-close-with-attrs', [], [], ['usage' => '<?= $heroUsage ?>', 'width' => '16', 'height' => '9']);
    self::assertStringContainsString('<?php', $result);
    self::assertStringContainsString('FileImageRenderer', $result);
}

private function assert_FileImageStaticMirror_testLiteralWithoutLayoutDoesNotMirror(): void
{
    $callback = Image::callback();
    $result = $callback('tag-self-close-with-attrs', [], [], ['asset' => 'some-asset-id', 'alt' => 'demo']);
    // Missing width/height/aspect_ratio → keep runtime PHP (CLS gate).
    self::assertStringContainsString('<?php', $result);
    self::assertStringContainsString('FileImageRenderer', $result);
}

private function assert_FileImageStaticMirror_testTryStaticMirrorReturnsNullWhenRequestContextMissing(): void
{
    // Without a live ScopeIdentity/locale, mirror must refuse rather than throw.
    $mirrored = Image::tryStaticMirror('tag-self-close-with-attrs', [], ['asset' => 'nonexistent-asset-for-mirror-test', 'alt' => 'demo', 'width' => '16', 'height' => '9', 'locale' => 'zh_Hans_CN']);
    self::assertNull($mirrored);
}
}
