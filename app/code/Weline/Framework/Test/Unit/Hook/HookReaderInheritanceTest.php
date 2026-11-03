<?php

declare(strict_types=1);

namespace Weline\Framework\test\Unit\Hook;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Hook\Config\HookReader;

/**
 * Hook 的「继承 / 覆写」语义：
 *
 * - hook 是**可继承**的：同一 hook 名可以由多个模块各自实现，全部生效（不是二选一）；
 * - 因此「模块对硬 hook 的覆写」必须出现，不能被一个不完整的注册表吞掉；
 * - 但同一模块的**已编译实现**是最终物，不会被文件系统扫描覆盖。
 */
class HookReaderInheritanceTest extends TestCase
{
    public function testStaleRegistryDoesNotHideOtherModuleHooks(): void
    {
        $registry = [
            'Weline_Theme' => ['file' => '/theme/body-end.phtml', 'priority' => 100, 'sort_order' => 1, 'solo' => false],
        ];
        $filesystem = [
            'Weline_Theme' => ['file' => '/theme/body-end.phtml', 'priority' => 100, 'sort_order' => 1, 'solo' => false],
            'Weline_Consent' => ['file' => '/consent/body-end.phtml', 'priority' => 80, 'sort_order' => 2, 'solo' => false],
            'Weline_Product' => ['file' => '/product/body-end.phtml', 'priority' => 60, 'sort_order' => 3, 'solo' => false],
        ];

        $merged = HookReader::mergeRegistryWithFilesystem($registry, $filesystem);

        self::assertArrayHasKey('Weline_Theme', $merged);
        self::assertArrayHasKey('Weline_Consent', $merged, '注册表里没有的模块实现必须补进来');
        self::assertArrayHasKey('Weline_Product', $merged);
        self::assertSame('/consent/body-end.phtml', $merged['Weline_Consent']['file']);
    }

    public function testRegisteredImplementationWinsForTheSameModule(): void
    {
        $registry = [
            'Weline_Theme' => ['file' => '/compiled/theme-body-end.phtml', 'priority' => 100, 'sort_order' => 1, 'solo' => true],
        ];
        $filesystem = [
            'Weline_Theme' => ['file' => '/source/theme-body-end.phtml', 'priority' => 100, 'sort_order' => 1, 'solo' => false],
        ];

        $merged = HookReader::mergeRegistryWithFilesystem($registry, $filesystem);

        self::assertSame('/compiled/theme-body-end.phtml', $merged['Weline_Theme']['file'], '已编译实现是最终物，不被文件扫描覆盖');
        self::assertTrue($merged['Weline_Theme']['solo']);
    }

    public function testEmptyFilesystemKeepsRegistry(): void
    {
        $registry = [
            'Weline_Theme' => ['file' => '/theme/x.phtml', 'priority' => 100, 'sort_order' => 1, 'solo' => false],
        ];

        self::assertSame($registry, HookReader::mergeRegistryWithFilesystem($registry, []));
    }
}
