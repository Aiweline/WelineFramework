<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Compilation;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Compilation\ModuleRegistryCompiler;

final class ModuleRegistryCompilerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/weline-module-compiler-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory . '/modules/Example/etc', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function testRejectsMissingProviderImplementationBeforePublishingRegistry(): void
    {
        $this->writeManifest('Missing\\Provider');
        $target = $this->temporaryDirectory . '/generated/modules.php';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Weline_Example provider example.runtime => Missing\\Provider is not loadable');

        try {
            (new ModuleRegistryCompiler())->compile(
                $this->temporaryDirectory . '/modules',
                $target,
            );
        } finally {
            self::assertFileDoesNotExist($target);
        }
    }

    public function testPublishesRegistryWhenProviderImplementationIsLoadable(): void
    {
        $this->writeManifest(LoadableModuleProvider::class);
        $target = $this->temporaryDirectory . '/generated/modules.php';

        $registry = (new ModuleRegistryCompiler())->compile(
            $this->temporaryDirectory . '/modules',
            $target,
        );

        self::assertFileExists($target);
        self::assertSame(
            LoadableModuleProvider::class,
            $registry['modules']['Weline_Example']['provides']['example.runtime'],
        );
    }

    private function writeManifest(string $implementation): void
    {
        $manifest = [
            'name' => 'Weline_Example',
            'version' => '1.0.0',
            'requires' => [],
            'optional' => [],
            'provides' => ['example.runtime' => $implementation],
        ];
        file_put_contents(
            $this->temporaryDirectory . '/modules/Example/etc/module.php',
            '<?php return ' . var_export($manifest, true) . ';',
        );
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

final class LoadableModuleProvider
{
}
