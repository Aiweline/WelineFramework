<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View\Taglib\Ast;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Api\Runtime\ProcessCacheResetter as FrameworkProcessCacheResetter;
use Weline\Framework\Runtime\ProcessMemoryReclaimFacade;
use Weline\Framework\View\Taglib\Ast\AstNodePoolReclaimable;
use Weline\Framework\View\Taglib\Ast\NodePool;
use Weline\Framework\View\Taglib\Ast\StringInterner;

final class NodePoolMemoryPressureContractTest extends TestCase
{
    protected function tearDown(): void
    {
        NodePool::reset();
        ProcessMemoryReclaimFacade::resetForTests();
        parent::tearDown();
    }

    public function testTrimForMemoryPressureHalvesPools(): void
    {
        NodePool::reset();
        for ($i = 0; $i < 10; $i++) {
            NodePool::textNode(1, 'text-' . $i);
            StringInterner::intern('str-' . $i);
        }
        $stringsBefore = StringInterner::size();
        NodePool::trimForMemoryPressure();
        $after = NodePool::stats();
        self::assertSame(5, $after['textNodes']);
        self::assertSame((int)\ceil($stringsBefore / 2), StringInterner::size());
    }

    public function testSoftViaReclaimableAndHardViaFrameworkResetter(): void
    {
        $facadeSrc = (string)\file_get_contents(
            (string)(new \ReflectionClass(ProcessMemoryReclaimFacade::class))->getFileName()
        );
        self::assertStringContainsString('AstNodePoolReclaimable', $facadeSrc);

        $resetterSrc = (string)\file_get_contents(
            (string)(new \ReflectionClass(FrameworkProcessCacheResetter::class))->getFileName()
        );
        self::assertStringContainsString('NodePool::reset()', $resetterSrc);

        $adapter = new AstNodePoolReclaimable();
        for ($i = 0; $i < 8; $i++) {
            NodePool::textNode(1, 'n-' . $i);
        }
        $adapter->compact();
        self::assertSame(4, NodePool::stats()['textNodes']);
    }
}
