<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\Service\Code3d;

use PHPUnit\Framework\TestCase;
use Weline\DeveloperWorkspace\Service\Code3d\ModuleDependencyMap;

/**
 * B0：模块依赖必须以 etc/module.php 为权威，requires/optional 分开；无 manifest 才回退 register.php。
 */
final class ModuleDependencyMapTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/code3d-dep-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root . '/app/code/VendorA/ModA/etc', 0777, true);
        \mkdir($this->root . '/app/code/VendorA/ModB', 0777, true);

        \file_put_contents(
            $this->root . '/app/code/VendorA/ModA/register.php',
            "<?php\nuse Weline\\Framework\\Register\\Register;\n"
            . "Register::register(Register::MODULE, 'VendorA_ModA', __DIR__, '1.0.0', 'desc', ['Legacy_Dep']);\n"
        );
        \file_put_contents(
            $this->root . '/app/code/VendorA/ModA/etc/module.php',
            "<?php\nreturn [\n"
            . "    'name' => 'VendorA_ModA',\n"
            . "    'version' => '1.0.0',\n"
            . "    'requires' => ['Weline_Framework' => '*', 'Weline_Order' => '*'],\n"
            . "    'optional' => ['Weline_B2B' => '*'],\n"
            . "    'provides' => [],\n"
            . "];\n"
        );
        \file_put_contents(
            $this->root . '/app/code/VendorA/ModB/register.php',
            "<?php\nuse Weline\\Framework\\Register\\Register;\n"
            . "Register::register(Register::MODULE, 'VendorA_ModB', __DIR__, '1.0.0', 'desc', ['Weline_Queue']);\n"
        );
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && \is_dir($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    public function testManifestIsAuthoritativeAndOptionalIsSeparated(): void
    {
        $detailed = (new ModuleDependencyMap())->loadDetailed($this->root);

        self::assertSame(['Weline_Framework', 'Weline_Order'], $detailed['VendorA_ModA']['requires']);
        self::assertSame(['Weline_B2B'], $detailed['VendorA_ModA']['optional']);
        self::assertNotContains('Legacy_Dep', $detailed['VendorA_ModA']['requires']);
    }

    public function testLegacyRegisterIsUsedOnlyWithoutManifest(): void
    {
        $detailed = (new ModuleDependencyMap())->loadDetailed($this->root);

        self::assertSame(['Weline_Queue'], $detailed['VendorA_ModB']['requires']);
        self::assertSame([], $detailed['VendorA_ModB']['optional']);
    }

    public function testLoadMergesRequiresAndOptionalForCompatibility(): void
    {
        $map = (new ModuleDependencyMap())->load($this->root);

        self::assertContains('Weline_Order', $map['VendorA_ModA']);
        self::assertContains('Weline_B2B', $map['VendorA_ModA']);
        self::assertNotContains('Legacy_Dep', $map['VendorA_ModA']);
    }

    private function removeDirectory(string $dir): void
    {
        $items = \scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (\is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
