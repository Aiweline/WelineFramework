<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Module\Manifest;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Module\Manifest\ModuleManifestReader;

final class ModuleManifestReaderTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/weline-module-manifest-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory . '/etc', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function testAuthoritativeManifestNormalizesDependencyShapes(): void
    {
        file_put_contents($this->temporaryDirectory . '/etc/module.php', <<<'PHP'
<?php
return [
    'name' => 'Weline_Example',
    'version' => '2.0.0',
    'requires' => ['Weline_Framework' => '^2.0', 'Weline_Api'],
    'optional' => ['Weline_Server' => '^2.0'],
    'provides' => ['example.runtime'],
];
PHP);

        $manifest = (new ModuleManifestReader())->read($this->temporaryDirectory);

        self::assertSame('Weline_Example', $manifest->name);
        self::assertSame('2.0.0', $manifest->version);
        self::assertSame([
            'Weline_Api' => '*',
            'Weline_Framework' => '^2.0',
        ], $manifest->requires);
        self::assertSame(['Weline_Server' => '^2.0'], $manifest->optional);
        self::assertTrue($manifest->authoritative);
    }

    public function testMissingManifestIsRejectedWithoutExplicitLegacyMode(): void
    {
        file_put_contents($this->temporaryDirectory . '/register.php', <<<'PHP'
<?php
Register::register(Register::MODULE, 'Weline_Legacy', __DIR__, '1.2.3', 'Legacy', ['Weline_Framework']);
PHP);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authoritative module manifest is missing');

        (new ModuleManifestReader())->read($this->temporaryDirectory);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
