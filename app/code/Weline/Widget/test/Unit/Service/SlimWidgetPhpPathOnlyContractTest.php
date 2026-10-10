<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Module extends Weline_Widget widget.php listings must be path-only strings.
 */
final class SlimWidgetPhpPathOnlyContractTest extends TestCase
{
    public function testAllModuleWidgetPhpListingsArePathOnly(): void
    {
        $root = \defined('BP') ? \rtrim((string) BP, '/\\') : dirname(__DIR__, 7);
        $pattern = $root . '/app/code/*/*/extends/module/Weline_Widget/*/widget.php';
        $files = glob($pattern) ?: [];
        self::assertNotEmpty($files, 'expected slim widget.php listings under app/code');

        foreach ($files as $file) {
            /** @var mixed $entries */
            $entries = require $file;
            self::assertIsArray($entries, $file);
            self::assertNotEmpty($entries, $file);
            foreach ($entries as $index => $entry) {
                self::assertIsString(
                    $entry,
                    $file . ' index ' . $index . ' must be a template path string (no array overrides)'
                );
                self::assertStringContainsString('::', $entry, $file);
                self::assertStringEndsWith('.phtml', $entry, $file);
            }
            $src = (string) file_get_contents($file);
            self::assertStringContainsString('只登记模板路径', $src, $file);
            self::assertStringNotContainsString('必要简化覆盖', $src, $file);
        }
    }
}
