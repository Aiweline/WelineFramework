<?php

declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
final class ThemeLayoutBoundShellReuseTest extends TestCase
{
    public function testMissingPublishedBindingDoesNotReuseUnpublishedDraftStructure(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/published-missing-binding.php') . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('published</div>', $result['html']);
        self::assertStringNotContainsString('draft-only', $result['html']);
    }

    public function testPublishedReleaseReturnsImmutableBoundShellWithoutWritingIt(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/bound-shell-reuse.php') . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($result['same_path']);
        self::assertSame(1234567890, $result['mtime']);
        self::assertSame('bound shell', $result['content']);
    }
}
