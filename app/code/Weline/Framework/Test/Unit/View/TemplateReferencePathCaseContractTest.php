<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Linux production filesystems are case-sensitive; macOS dev checkouts are not.
 * A `Weline_X::templates/...` reference whose casing differs from disk renders on
 * macOS and throws "模板文件不存在" in production.
 */
final class TemplateReferencePathCaseContractTest extends TestCase
{
    private const SKIP_DIRS = ['Test', 'test', 'UnitTest', 'doc', 'node_modules', 'vendor', 'statics', 'i18n'];

    /** @var array<string, list<string>|false> */
    private array $dirListing = [];

    public function testModuleTemplateReferencesMatchOnDiskCase(): void
    {
        $vendorRoot = dirname(__DIR__, 4);
        $mismatches = [];

        foreach ($this->sourceFiles($vendorRoot) as $file) {
            $source = (string)file_get_contents($file);
            if (!str_contains($source, '::templates/')) {
                continue;
            }
            if (!preg_match_all('/Weline_([A-Za-z0-9]+)::(templates\/[A-Za-z0-9_\/.\-]+\.phtml)/', $source, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as [$reference, $module, $path]) {
                $moduleDir = $vendorRoot . DIRECTORY_SEPARATOR . $module;
                if (!is_dir($moduleDir)) {
                    continue;
                }
                $relative = 'view/' . $path;
                if (!is_file($moduleDir . '/' . $relative) || $this->existsWithExactCase($moduleDir, $relative)) {
                    continue;
                }
                $mismatches[$reference] = substr($file, strlen($vendorRoot) + 1);
            }
        }

        ksort($mismatches);
        $report = [];
        foreach ($mismatches as $reference => $file) {
            $report[] = $reference . '  (in ' . $file . ')';
        }
        self::assertSame([], $report, "Template references differ from on-disk case:\n" . implode("\n", $report));
    }

    /**
     * @return iterable<string>
     */
    private function sourceFiles(string $vendorRoot): iterable
    {
        $directory = new \RecursiveDirectoryIterator($vendorRoot, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            static function (\SplFileInfo $current): bool {
                if ($current->isDir()) {
                    return !in_array($current->getFilename(), self::SKIP_DIRS, true);
                }
                return in_array($current->getExtension(), ['php', 'phtml', 'xml'], true);
            },
        );
        foreach (new \RecursiveIteratorIterator($filter) as $info) {
            yield $info->getPathname();
        }
    }

    private function existsWithExactCase(string $base, string $relative): bool
    {
        $current = $base;
        foreach (explode('/', $relative) as $segment) {
            if (!isset($this->dirListing[$current])) {
                $entries = @scandir($current);
                $this->dirListing[$current] = $entries === false ? false : $entries;
            }
            $entries = $this->dirListing[$current];
            if ($entries === false || !in_array($segment, $entries, true)) {
                return false;
            }
            $current .= '/' . $segment;
        }

        return true;
    }
}
