<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Test\Console\E2e\Run;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * E2E 收集契约：spec 之间禁止互相 require/import。
 *
 * Playwright 对「test 文件 import 另一个 test 文件」是 fail-closed 的
 * （should not import test file）。全量收集时一个这样的文件会让所有
 * `--case-id` / 全量运行一起失败；历史缺陷：MediaManager plan-suite 只写了
 * 一行 require。
 */
final class E2eCollectionContractTest extends TestCase
{
    private const SPEC_IMPORT_PATTERN = '/(?:require|import)\s*\(?\s*[\'"][^\'"]*\.spec\.js[\'"]/';

    public function testNoSpecFileImportsAnotherSpecFile(): void
    {
        $violations = [];
        foreach ($this->specFiles() as $file) {
            $source = (string)@\file_get_contents($file);
            if ($source === '') {
                continue;
            }
            if (\preg_match(self::SPEC_IMPORT_PATTERN, $source, $matches) === 1) {
                $relative = \str_replace(BP, '', $file);
                $violations[] = $relative . ' → ' . \trim($matches[0]);
            }
        }

        self::assertSame([], $violations, "spec 之间禁止互相引用（Playwright fail-closed）：\n" . \implode("\n", $violations));
    }

    public function testCaseIdResolvesToDeclaringFilesOnly(): void
    {
        $tmpDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'weline-e2e-case-' . \getmypid();
        if (!\is_dir($tmpDir)) {
            \mkdir($tmpDir, 0775, true);
        }
        $withCase = $tmpDir . \DIRECTORY_SEPARATOR . 'with-case.spec.js';
        $without = $tmpDir . \DIRECTORY_SEPARATOR . 'without-case.spec.js';
        \file_put_contents($withCase, "moduleCase(test, { module: 'X', id: 'CASE-001' }, 'x', async () => {});\n");
        \file_put_contents($without, "moduleCase(test, { module: 'X', id: 'OTHER-002' }, 'y', async () => {});\n");

        try {
            $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod(Run::class, 'resolveCaseFiles');
            $files = $method->invoke($run, 'CASE-001', ['all_test_files' => [$withCase, $without]]);
        } finally {
            @\unlink($withCase);
            @\unlink($without);
            @\rmdir($tmpDir);
        }

        self::assertSame([$withCase], $files);
    }

    /**
     * @return list<string>
     */
    private function specFiles(): array
    {
        $roots = [];
        $appCode = BP . 'app' . \DIRECTORY_SEPARATOR . 'code';
        foreach ((array)@\scandir($appCode) as $vendor) {
            if (!\is_string($vendor) || $vendor === '' || $vendor[0] === '.') {
                continue;
            }
            foreach ((array)@\scandir($appCode . \DIRECTORY_SEPARATOR . $vendor) as $module) {
                if (!\is_string($module) || $module === '' || $module[0] === '.') {
                    continue;
                }
                foreach (['test', 'Test'] as $case) {
                    foreach (['e2e', 'E2E'] as $layer) {
                        $dir = $appCode . \DIRECTORY_SEPARATOR . $vendor . \DIRECTORY_SEPARATOR
                            . $module . \DIRECTORY_SEPARATOR . $case . \DIRECTORY_SEPARATOR . $layer;
                        if (\is_dir($dir)) {
                            $roots[] = $dir;
                        }
                    }
                }
            }
        }

        $files = [];
        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ));
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                    continue;
                }
                if (\str_ends_with($fileInfo->getFilename(), '.spec.js')) {
                    $files[] = $fileInfo->getPathname();
                }
            }
        }

        return $files;
    }
}
