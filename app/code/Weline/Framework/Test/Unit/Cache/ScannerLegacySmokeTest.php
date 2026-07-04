<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache;

use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertIsArray;

class ScannerLegacySmokeTest extends TestCase
{
    public function testGetCachesReturnsStructuredArray(): void
    {
        $scanner = new Scanner();
        $data    = $scanner->getCaches();

        assertIsArray($data);
        assertIsArray($data['pools'] ?? null);
    }

    public function testGetPoolListReturnsArray(): void
    {
        $scanner = new Scanner();
        $data    = $scanner->getPoolList();

        assertIsArray($data);
    }
}
