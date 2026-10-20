<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Env;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\Service\ModuleEnvDir;

final class ModuleEnvDirTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/weline-mod-env-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testPlainModuleUsesLowercaseEnv(): void
    {
        $this->write($this->root . '/Server/env/requirements.php', '<?php return [];');

        $dir = ModuleEnvDir::resolve($this->root . '/Server');

        self::assertSame($this->root . '/Server/env/', $dir);
    }

    public function testFrameworkPackageUsesNestedEnvWhenRootContractMissing(): void
    {
        $this->write($this->root . '/Framework/Env/env/requirements.php', '<?php return [];');

        $dir = ModuleEnvDir::resolve($this->root . '/Framework');

        self::assertSame($this->root . '/Framework/Env/env/', $dir);
    }

    public function testDistinctCaseSplitPrefersNestedContract(): void
    {
        $this->write($this->root . '/Framework/env/requirements.php', '<?php return ["shim"];');
        $this->write($this->root . '/Framework/Env/env/requirements.php', '<?php return ["real"];');

        $dir = ModuleEnvDir::resolve($this->root . '/Framework/');

        self::assertSame($this->root . '/Framework/Env/env/', $dir);
    }

    public function testMissingContractReturnsNull(): void
    {
        mkdir($this->root . '/Empty', 0777, true);

        self::assertNull(ModuleEnvDir::resolve($this->root . '/Empty'));
    }

    private function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
