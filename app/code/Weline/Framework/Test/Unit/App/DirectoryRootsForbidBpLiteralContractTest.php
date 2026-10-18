<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;

/**
 * Production code under app/code/Weline must not hardcode BP.'var'|BP.'pub' roots
 * after DirectoryList bootstrap (relocatable directories).
 */
final class DirectoryRootsForbidBpLiteralContractTest extends TestCase
{
    public function testProductionPhpForbidsBpVarAndBpPubLiterals(): void
    {
        $root = \dirname(__DIR__, 5); // .../Weline
        self::assertDirectoryExists($root);
        $allowSuffixes = [
            '/Framework/App/DirectoryList.php',
            '/Framework/Test/Unit/App/DirectoryListTest.php',
            '/Framework/Test/Unit/App/DirectoryRootsForbidBpLiteralContractTest.php',
        ];
        $patterns = [
            "/BP\\s*\\.\\s*['\"]var['\"]/",
            "/BP\\s*\\.\\s*['\"]pub['\"]/",
            "/BP\\s*\\.\\s*['\"]\\/pub/",
            "/BP\\s*\\.\\s*['\"]\\/pub\\/media/",
        ];
        $hits = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $norm = \str_replace('\\', '/', $path);
            if (\preg_match('#/(test|Test|tests)/#', $norm)) {
                continue;
            }
            foreach ($allowSuffixes as $suffix) {
                if (\str_ends_with($norm, $suffix)) {
                    continue 2;
                }
            }
            $src = (string)\file_get_contents($path);
            foreach ($patterns as $pattern) {
                if (\preg_match($pattern, $src)) {
                    $hits[] = \substr($norm, \strlen(\str_replace('\\', '/', \dirname($root, 2))) + 1)
                        . ' matches ' . $pattern;
                    break;
                }
            }
        }
        self::assertSame([], $hits, "Forbidden BP.var/pub literals:\n" . \implode("\n", $hits));
    }
}
