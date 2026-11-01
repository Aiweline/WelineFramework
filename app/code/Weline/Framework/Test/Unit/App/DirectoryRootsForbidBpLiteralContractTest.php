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
            // 这两个是安装密钥缺失时的 hash 种子，不是文件系统根目录：
            // 换成 VAR_DIR 会改变派生密钥，已签发的扩展 token / 配对全部失效。
            '/Ai/Controller/Backend/Extension/Pairing.php',
            '/Ai/Service/Extension/ExtensionTokenService.php',
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
