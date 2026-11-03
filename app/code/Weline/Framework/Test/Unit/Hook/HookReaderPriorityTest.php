<?php

declare(strict_types=1);

namespace Weline\Framework\test\Unit\Hook;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Hook\Config\HookReader;

/**
 * hook 优先级与 solo 语义：生产渲染（Template / HookCompiledPhpInliner）与这里用的是
 * 同一份实现（`HookReader::orderByPriority()` / `applySolo()`），所以这组断言就是线上行为。
 */
class HookReaderPriorityTest extends TestCase
{
    public function testPriorityDescendingThenSortOrderAscending(): void
    {
        $ordered = HookReader::orderByPriority([
            'Mod_Low' => ['file' => 'a', 'priority' => 10, 'sort_order' => 1, 'solo' => false],
            'Mod_High' => ['file' => 'b', 'priority' => 90, 'sort_order' => 9, 'solo' => false],
            'Mod_Mid_Late' => ['file' => 'c', 'priority' => 50, 'sort_order' => 5, 'solo' => false],
            'Mod_Mid_Early' => ['file' => 'd', 'priority' => 50, 'sort_order' => 2, 'solo' => false],
        ]);

        self::assertSame(
            ['Mod_High', 'Mod_Mid_Early', 'Mod_Mid_Late', 'Mod_Low'],
            \array_keys($ordered),
            'priority 降序；同优先级按 sort_order 升序'
        );
    }

    public function testSamePriorityAndSortOrderFallsBackToModuleName(): void
    {
        $ordered = HookReader::orderByPriority([
            'Zeta' => ['file' => 'z', 'priority' => 50, 'sort_order' => 1, 'solo' => false],
            'Alpha' => ['file' => 'a', 'priority' => 50, 'sort_order' => 1, 'solo' => false],
        ]);

        self::assertSame(['Alpha', 'Zeta'], \array_keys($ordered));
    }

    public function testSoloKeepsOnlyThatImplementation(): void
    {
        $files = [
            'Weline_Theme' => 'Weline_Theme::hooks/body-end.phtml',
            'Weline_Consent' => 'Weline_Consent::hooks/body-end.phtml',
        ];
        $meta = [
            'Weline_Theme' => ['solo' => false],
            'Weline_Consent' => ['solo' => true],
        ];

        self::assertSame(
            ['Weline_Consent' => 'Weline_Consent::hooks/body-end.phtml'],
            HookReader::applySolo($files, $meta),
            'solo=true 时只渲染它'
        );
    }

    public function testWithoutSoloAllImplementationsSurvive(): void
    {
        $files = [
            'Weline_Theme' => 'Weline_Theme::hooks/body-end.phtml',
            'Weline_Consent' => 'Weline_Consent::hooks/body-end.phtml',
        ];
        $meta = [
            'Weline_Theme' => ['solo' => false],
            'Weline_Consent' => ['solo' => false],
        ];

        self::assertSame($files, HookReader::applySolo($files, $meta), '没有 solo 时全部保留（可继承）');
    }

    public function testSoloForModuleWithoutFileDoesNotDropEverything(): void
    {
        $files = ['Weline_Theme' => 'Weline_Theme::hooks/body-end.phtml'];
        $meta = ['Weline_Consent' => ['solo' => true]];

        self::assertSame($files, HookReader::applySolo($files, $meta), 'solo 指向不存在的实现时不能把整段 hook 清空');
    }
}
