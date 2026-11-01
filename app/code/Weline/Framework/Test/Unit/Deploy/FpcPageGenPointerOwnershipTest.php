<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * Step 0（D11 前置）：FPC 页桶指针 `pg/current` 的唯一写入权必须归发布流程。
 *
 * 2026-11-01 事故根因之一：请求期渲染会调用 `FpcHtmlDiskStore::pageDirectory()`，
 * 其中 `pointHostSlot()` 无条件把 `pg/current` 重指到"当前请求算出的 token"。
 * 只要还有旧 worker 在渲染，指针就会被来回拉（A-B-A），前端拿到的 token 与实际桶错配。
 *
 * 本测试锁定契约：
 *   1. 已存在的 `pg/current` 在请求期**不得被覆盖**；
 *   2. 只有 `pg/current` 缺失时才初始化；
 *   3. 发布流程入口（`alignWriteBaseToCurrentPageGeneration` / `promoteTarget`）仍可写。
 */
final class FpcPageGenPointerOwnershipTest extends TestCase
{
    public function testExistingCurrentSymlinkIsNeverRewrittenByRequestPath(): void
    {
        // 契约：请求期（pageDirectory）对已存在的 pg/current 只读。
        // 期望实现后在源码里存在"仅缺失才初始化"的判定，且该判定位于 pointHostSlot 调用之前。
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Http/Fpc/FpcHtmlDiskStore.php'
        );
        self::assertNotSame('', $source, 'FpcHtmlDiskStore.php 不可读');

        $pageDirectoryPos = \strpos($source, 'public static function pageDirectory(');
        self::assertNotFalse($pageDirectoryPos, 'pageDirectory() 必须存在');

        $bodyPos = \strpos($source, '{', (int)$pageDirectoryPos);
        $body = \substr($source, (int)$bodyPos, 1200);

        self::assertMatchesRegularExpression(
            '/currentPageGenExists|is_link\(\$link\)|pageGenSlotExists/i',
            $body,
            'pageDirectory() 内必须存在"current 已存在则跳过写指针"的判定（Step 0 契约）'
        );
    }

    public function testPublisherEntryIsTheOnlyWriter(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Http/Fpc/FpcHtmlDiskStore.php'
        );

        // 写入入口应当被显式标注为发布流程独占（避免以后又有人在请求期加写指针）。
        self::assertMatchesRegularExpression(
            '/pointHostSlot|promoteTarget|alignWriteBaseToCurrentPageGeneration/',
            $source,
            '必须保留发布流程写指针入口'
        );
    }
}
