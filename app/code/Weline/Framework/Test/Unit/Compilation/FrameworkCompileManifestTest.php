<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Compilation;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Compilation\FrameworkCompileManifest;

final class FrameworkCompileManifestTest extends TestCase
{
    /**
     * Runtime template materialization lives below each module's view/tpl
     * directory and may continue while a framework generation is compiled.
     * Those products must not invalidate the PHP source manifest.
     */
    public function testRuntimeTemplateProductsAreExcludedFromSourceManifest(): void
    {
        $root = sys_get_temp_dir() . '/weline-compile-manifest-' . bin2hex(random_bytes(6));
        $hooks = $root . '-hooks.php';
        mkdir($root . '/Example/view/tpl', 0777, true);
        file_put_contents($root . '/Example/Source.php', "<?php return 'source';\n");
        file_put_contents($root . '/Example/view/tpl/generated.php', "<?php return 'v1';\n");
        file_put_contents($hooks, "<?php return [];\n");

        try {
            $manifest = new FrameworkCompileManifest();
            $before = $manifest->capture($root, $hooks);

            self::assertArrayHasKey('Example/Source.php', $before['sources']);
            self::assertArrayNotHasKey('Example/view/tpl/generated.php', $before['sources']);

            file_put_contents($root . '/Example/view/tpl/generated.php', "<?php return 'v2';\n");
            $after = $manifest->capture($root, $hooks, $before['sources']);

            self::assertTrue($manifest->sameSourceState($before, $after));
        } finally {
            @unlink($root . '/Example/view/tpl/generated.php');
            @rmdir($root . '/Example/view/tpl');
            @rmdir($root . '/Example');
            @rmdir($root);
            @unlink($hooks);
        }
    }

    public function testDescribeSourceDeltaReportsAddedRemovedAndChangedFiles(): void
    {
        $root = sys_get_temp_dir() . '/weline-compile-delta-' . bin2hex(random_bytes(6));
        $hooks = $root . '-hooks.php';
        mkdir($root . '/Example', 0777, true);
        file_put_contents($root . '/Example/Keep.php', "<?php return 'keep';\n");
        file_put_contents($root . '/Example/Gone.php', "<?php return 'gone';\n");
        file_put_contents($root . '/Example/Change.php', "<?php return 'v1-long';\n");
        file_put_contents($hooks, "<?php return [];\n");

        try {
            $manifest = new FrameworkCompileManifest();
            $before = $manifest->capture($root, $hooks);
            unlink($root . '/Example/Gone.php');
            file_put_contents($root . '/Example/New.php', "<?php return 'new';\n");
            // Different byte length so same-second mtime reuse cannot hide the edit.
            file_put_contents($root . '/Example/Change.php', "<?php return 'v2';\n");
            $after = $manifest->capture($root, $hooks, $before['sources']);

            $delta = $manifest->describeSourceDelta($before, $after);
            self::assertContains('-Example/Gone.php', $delta);
            self::assertContains('+Example/New.php', $delta);
            self::assertContains('~Example/Change.php', $delta);
            self::assertFalse($manifest->sameSourceState($before, $after));
        } finally {
            @unlink($root . '/Example/Keep.php');
            @unlink($root . '/Example/New.php');
            @unlink($root . '/Example/Change.php');
            @unlink($root . '/Example/Gone.php');
            @rmdir($root . '/Example');
            @rmdir($root);
            @unlink($hooks);
        }
    }
}
