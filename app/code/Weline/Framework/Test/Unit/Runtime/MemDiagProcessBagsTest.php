<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Runtime\MemDiag;
use Weline\Framework\Runtime\RequestContext;

/**
 * Ch5：MemDiag 暴露命名进程袋（name / entries / bytes），且登记数不随调用增长。
 */
final class MemDiagProcessBagsTest extends TestCase
{
    private const TX_STATES_KEY = 'framework.database.transaction_states';

    protected function setUp(): void
    {
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context());
        RequestContext::init();
        RequestContext::setId(null);
        RequestContext::set(self::TX_STATES_KEY, []);
    }

    protected function tearDown(): void
    {
        RequestContext::set(self::TX_STATES_KEY, []);
        RequestContext::setId(null);
        if (Context::hasCurrent()) {
            Context::leave();
        }
    }

    public function testSnapshotReportsProcessBagEntriesAndBytes(): void
    {
        $bag = [];
        for ($i = 0; $i < 10; $i++) {
            $words = &DictionaryCacheNamespace::processBag('memdiag.test.bag', $bag, 64, ['zh_Hans_CN']);
            $words['word' . $i] = \str_repeat('x', 32);
        }

        $snapshot = MemDiag::cacheSnapshot();

        self::assertArrayHasKey('memdiag.test.bag', $snapshot['process_bags']);
        self::assertSame(10, $snapshot['process_bags']['memdiag.test.bag']['entries']);
        self::assertGreaterThanOrEqual(320, $snapshot['process_bags']['memdiag.test.bag']['bytes']);
        self::assertGreaterThanOrEqual(10, $snapshot['process_bag_entries']);
        self::assertGreaterThanOrEqual(320, $snapshot['process_bag_bytes']);
    }

    public function testRegisteredBagCountIsStableAcrossRepeatedCalls(): void
    {
        $bag = [];
        $before = \count(DictionaryCacheNamespace::registeredBagNames());

        for ($i = 0; $i < 500; $i++) {
            $words = &DictionaryCacheNamespace::processBag('memdiag.test.stable', $bag, 32, ['zh_Hans_CN']);
            $words['k' . ($i % 32)] = $i;
        }

        self::assertSame($before + 1, \count(DictionaryCacheNamespace::registeredBagNames()));
    }
}
