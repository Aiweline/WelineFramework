<?php

declare(strict_types=1);

namespace Weline\Database\test;

use PHPUnit\Framework\TestCase;
use Weline\Database\Service\Artifact\LocalModuleArtifactProvider;

final class LocalModuleArtifactProviderTest extends TestCase
{
    private string $source = '';
    private string $artifactRoot = '';

    protected function tearDown(): void
    {
        $this->removeDirectory($this->source);
        $this->removeDirectory($this->artifactRoot);
        parent::tearDown();
    }

    public function testSnapshotIsAtomicReusableAndRejectsIncompleteTarget(): void
    {
        $nonce = substr(hash('sha256', uniqid('', true)), 0, 12);
        $module = 'Fixture_Artifact' . $nonce;
        $version = '1.2.3';
        $this->source = sys_get_temp_dir() . DS . 'weline-artifact-source-' . $nonce;
        self::assertTrue(mkdir($this->source . DS . 'etc', 0755, true));
        file_put_contents(
            $this->source . DS . 'etc' . DS . 'module.php',
            "<?php\nreturn ['name' => '" . $module . "', 'version' => '" . $version . "'];\n"
        );
        file_put_contents($this->source . DS . 'payload.txt', $nonce);

        $provider = new LocalModuleArtifactProvider();
        $first = $provider->importDirectory($module, $version, 'op-' . $nonce, $this->source, 'test');
        self::assertTrue($first['success'], (string)($first['error'] ?? ''));
        self::assertDirectoryExists((string)$first['path']);
        $this->artifactRoot = dirname((string)$first['path']);
        self::assertFileExists($this->artifactRoot . DS . 'manifest.json');

        $second = $provider->importDirectory($module, $version, 'op-reuse-' . $nonce, $this->source, 'test');
        self::assertTrue($second['success'], (string)($second['error'] ?? ''));
        self::assertSame($first['checksum'], $second['checksum']);
        self::assertSame($first['path'], $second['path']);

        $this->removeDirectory((string)$first['path']);
        $incomplete = $provider->importDirectory($module, $version, 'op-incomplete-' . $nonce, $this->source, 'test');
        self::assertFalse($incomplete['success']);
        self::assertStringContainsString('不完整', (string)($incomplete['error'] ?? ''));
    }

    private function removeDirectory(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
