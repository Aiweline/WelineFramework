<?php

declare(strict_types=1);

namespace Weline\Hook\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Hook\HookScanner;

final class HookScannerLegacyShortNameTest extends TestCase
{
    public function testMatchingModuleShortNameCanDeclareLegacyShortHook(): void
    {
        $basePath = $this->createHookModuleFixture('seo::head');

        try {
            $config = $this->scanModuleHookConfig('Weline_Seo', $basePath);

            self::assertIsArray($config);
            self::assertArrayHasKey('seo::head', $config);
            self::assertSame('doc/hook/seo/head.md', $config['seo::head']['doc_path']);
            self::assertTrue($config['seo::head']['has_spec']);
            self::assertTrue($config['seo::head']['has_doc']);
        } finally {
            $this->removeDirectory($basePath);
        }
    }

    public function testMismatchedModuleShortNameCannotDeclareLegacyShortHook(): void
    {
        $basePath = $this->createHookModuleFixture('seo::head');

        try {
            $this->expectException(\RuntimeException::class);

            $this->scanModuleHookConfig('Weline_Theme', $basePath);
        } finally {
            $this->removeDirectory($basePath);
        }
    }

    private function createHookModuleFixture(string $hookName): string
    {
        $basePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline-hook-scanner-'
            . bin2hex(random_bytes(4));

        $docPath = $basePath . DIRECTORY_SEPARATOR . 'doc' . DIRECTORY_SEPARATOR . 'hook' . DIRECTORY_SEPARATOR . 'seo';
        self::assertTrue(mkdir($docPath, 0777, true));
        self::assertNotFalse(file_put_contents($docPath . DIRECTORY_SEPARATOR . 'head.md', '# SEO head' . PHP_EOL));

        $hookConfig = <<<PHP
<?php

return [
    '{$hookName}' => [
        'name' => 'SEO head',
        'description' => 'SEO metadata head hook.',
        'doc' => 'seo/head.md',
    ],
];
PHP;

        self::assertNotFalse(file_put_contents($basePath . DIRECTORY_SEPARATOR . 'hook.php', $hookConfig));

        return $basePath;
    }

    private function scanModuleHookConfig(string $moduleName, string $basePath): ?array
    {
        $scanner = new HookScanner();
        $method = new \ReflectionMethod(HookScanner::class, 'scanModuleHookConfig');
        $method->setAccessible(true);

        $result = $method->invoke($scanner, $moduleName, $basePath);

        self::assertTrue(is_array($result) || $result === null);

        return $result;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
