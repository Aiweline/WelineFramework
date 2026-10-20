<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;

/**
 * 袋身份契约（Ch1）：
 * - 请求袋永不登记进进程表（不产生 anon 条目、不写 Store）；
 * - 进程袋身份 = 显式名称，命名幂等；
 * - 淘汰使用 90% 滞后水位（一次削到 90%，后续插入摊还 O(1)）。
 */
final class ProcessBagIdentityContractTest extends TestCase
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
        Parser::clearWorkerCaches();
    }

    protected function tearDown(): void
    {
        Parser::clearWorkerCaches();
        RequestContext::set(self::TX_STATES_KEY, []);
        RequestContext::setId(null);
        if (Context::hasCurrent()) {
            Context::leave();
        }
    }

    public function testRequestBagNeverRegistersInProcessTable(): void
    {
        $before = DictionaryCacheNamespace::registeredBagNames();

        for ($i = 0; $i < 200; $i++) {
            $bag = [];
            $words = &DictionaryCacheNamespace::requestBag($bag, 8, ['zh_Hans_CN']);
            $words['key' . $i] = $i;
        }

        self::assertSame($before, DictionaryCacheNamespace::registeredBagNames());
    }

    public function testRequestBagDoesNotWriteProcessStore(): void
    {
        $store = DictionaryCacheNamespace::processMemoryStore();
        $before = $store->getItemCount();

        for ($i = 0; $i < 100; $i++) {
            $bag = [];
            $words = &DictionaryCacheNamespace::requestBag($bag, 8, ['zh_Hans_CN']);
            $words['key' . $i] = $i;
        }

        self::assertSame($before, $store->getItemCount());
        self::assertSame([], $store->keysInBucket('zh_Hans_CN'));
    }

    public function testProcessBagIsRegisteredOnceByName(): void
    {
        $before = DictionaryCacheNamespace::registeredBagNames();
        $bag = [];

        for ($i = 0; $i < 20; $i++) {
            $words = &DictionaryCacheNamespace::processBag('phrase.test.named_bag', $bag, 8, ['zh_Hans_CN']);
            $words['key' . $i] = $i;
        }

        $after = DictionaryCacheNamespace::registeredBagNames();
        self::assertContains('phrase.test.named_bag', $after);
        self::assertSame(\count($before) + 1, \count($after));
        self::assertSame($before, \array_values(\array_diff($after, ['phrase.test.named_bag'])));
    }

    public function testProcessBagTrimsToNinetyPercentWatermark(): void
    {
        $bag = [];
        for ($i = 0; $i < 100; $i++) {
            $bag['key' . $i] = $i;
        }

        $words = &DictionaryCacheNamespace::processBag('phrase.test.hysteresis', $bag, 20, []);
        self::assertCount(18, $words, '满袋一次削到 90% 水位，而不是逐条削。');
        self::assertArrayHasKey('key99', $words, '保留最新条目。');
        self::assertArrayNotHasKey('key0', $words);
    }

    public function testRequestBagTrimsToNinetyPercentWatermarkWithoutRegistering(): void
    {
        $before = DictionaryCacheNamespace::registeredBagNames();
        $bag = [];
        for ($i = 0; $i < 100; $i++) {
            $bag['key' . $i] = $i;
        }

        $words = &DictionaryCacheNamespace::requestBag($bag, 20, []);
        self::assertCount(18, $words);
        self::assertSame($before, DictionaryCacheNamespace::registeredBagNames());
    }

    public function testProcessBagLocaleMarkerIsCoalescedAcrossUnchangedCalls(): void
    {
        $store = DictionaryCacheNamespace::processMemoryStore();
        $bag = ['a' => 1];

        DictionaryCacheNamespace::processBag('phrase.test.marker', $bag, 8, ['zh_Hans_CN']);
        $afterFirst = $store->getItemCount();

        for ($i = 0; $i < 50; $i++) {
            DictionaryCacheNamespace::processBag('phrase.test.marker', $bag, 8, ['zh_Hans_CN']);
        }

        self::assertSame($afterFirst, $store->getItemCount());
    }
}
