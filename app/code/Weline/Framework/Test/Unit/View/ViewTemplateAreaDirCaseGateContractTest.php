<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * view/templates|theme 与 app/design 下区段目录必须小写 frontend|backend。
 */
final class ViewTemplateAreaDirCaseGateContractTest extends TestCase
{
    public function testNoPascalCaseAreaDirsUnderTemplatesOrTheme(): void
    {
        $vendorRoot = \dirname(__DIR__, 5); // app/code
        $repoRoot = \dirname($vendorRoot, 2); // repo root
        $hits = [];
        foreach ([$vendorRoot, $repoRoot . '/app/design'] as $base) {
            if (!\is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $info) {
                if (!$info->isDir()) {
                    continue;
                }
                $path = \str_replace('\\', '/', $info->getPathname());
                if (\str_contains($path, '/view/tpl/') || \str_contains($path, '/tpl/')) {
                    continue;
                }
                $name = $info->getFilename();
                $parent = \basename(\dirname($path));
                if (\in_array($parent, ['templates', 'theme'], true) && \in_array($name, ['Backend', 'Frontend'], true)) {
                    $hits[] = \substr($path, \strlen($repoRoot) + 1);
                }
            }
        }
        \sort($hits);
        self::assertSame([], $hits, "PascalCase area dirs remain:\n" . \implode("\n", $hits));
    }
}
