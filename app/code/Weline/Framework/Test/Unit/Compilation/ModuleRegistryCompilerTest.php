<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Compilation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Architecture\Module\ModuleGraphValidator;
use Weline\Framework\Compilation\ModuleRegistryCompiler;
use Weline\Framework\Module\Manifest\ModuleManifest;

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

    #[DataProvider('dependencyConstraintProvider')]
    public function testValidatesDependencyConstraintSyntaxUsedByModuleCompiler(
        string $actualVersion,
        string $constraint,
        bool $matches,
    ): void {
        $dependency = new ModuleManifest(
            'Weline_Dependency',
            $actualVersion,
            [],
            [],
            [],
            '/modules/Dependency',
        );
        $consumer = new ModuleManifest(
            'Weline_Example',
            '1.0.0',
            ['Weline_Dependency' => $constraint],
            [],
            [],
            '/modules/Example',
        );

        $errors = (new ModuleGraphValidator())->validate([
            $dependency->name => $dependency,
            $consumer->name => $consumer,
        ]);

        if ($matches) {
            self::assertSame([], $errors);
            return;
        }
        self::assertSame([
            "Weline_Example requires Weline_Dependency {$constraint}, installed version is {$actualVersion}.",
        ], $errors);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function dependencyConstraintProvider(): array
    {
        return [
            'greater-or-equal boundary' => ['1.2.0', '>=1.2.0', true],
            'greater-or-equal newer' => ['1.3.0', '>=1.2.0', true],
            'greater-or-equal lower' => ['1.1.9', '>=1.2.0', false],
            'less-or-equal boundary' => ['1.2.0', '<=1.2.0', true],
            'less-or-equal older' => ['1.1.9', '<=1.2.0', true],
            'less-or-equal newer' => ['1.2.1', '<=1.2.0', false],
            'strict greater' => ['1.2.1', '>1.2.0', true],
            'strict greater boundary' => ['1.2.0', '>1.2.0', false],
            'strict less' => ['1.1.9', '<1.2.0', true],
            'strict less boundary' => ['1.2.0', '<1.2.0', false],
            'not equal' => ['1.2.1', '!=1.2.0', true],
            'not equal boundary' => ['1.2.0', '!=1.2.0', false],
            'single equal' => ['1.2.0', '=1.2.0', true],
            'single equal mismatch' => ['1.2.1', '=1.2.0', false],
            'double equal' => ['1.2.0', '==1.2.0', true],
            'double equal mismatch' => ['1.2.1', '==1.2.0', false],
            'exact version' => ['1.2.0', '1.2.0', true],
            'exact version mismatch' => ['1.2.1', '1.2.0', false],
            'caret same major' => ['1.9.0', '^1.2.0', true],
            'caret next major' => ['2.0.0', '^1.2.0', false],
            'tilde same minor' => ['1.2.9', '~1.2.0', true],
            'tilde next minor' => ['1.3.0', '~1.2.0', false],
            'wildcard' => ['99.0.0', '*', true],
        ];
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
