<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Sample\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Sample\Hanfu1688\RunArtifactStore;

final class RunArtifactStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hanfu-1688-artifacts-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        foreach (array_reverse(glob($this->root . '/*/*') ?: []) as $path) {
            is_file($path) && unlink($path);
        }
        foreach (array_reverse(glob($this->root . '/*') ?: []) as $path) {
            is_dir($path) && rmdir($path);
        }
        rmdir($this->root);
    }

    public function testJsonArtifactsAreAtomicPrivateAndReadable(): void
    {
        $store = new RunArtifactStore($this->root);
        $path = $store->writeJson('hanfu-1688-test', 'verified-sources.json', [
            'contract' => 'hanfu.1688.sources.v1',
            'value' => '汉服',
        ]);

        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame('汉服', $store->readJson('hanfu-1688-test', 'verified-sources.json')['value']);
        self::assertSame([], glob(dirname($path) . '/.*.tmp-*') ?: []);
    }

    public function testUnknownArtifactNameIsRejected(): void
    {
        $this->expectExceptionMessage('hanfu_1688_artifact_name_invalid');
        (new RunArtifactStore($this->root))->writeJson('hanfu-1688-test', 'secret.json', []);
    }
}
