<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Weline\Framework\Context;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Transaction\TransactionState;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;

/**
 * W2：Phrase 进程袋委托 ProcessMemoryStore（bucket=locale）。
 * 覆盖：locale 桶 pin 保护、压力踢冷语种、事务 ephemeral、clearWorkerCaches 清 Store。
 */
final class DictionaryCacheNamespaceProcessStoreMigrationTest extends TestCase
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

    public function testLocalCacheIsEphemeralDuringActiveTransactionAndDoesNotPublishToStore(): void
    {
        $query = $this->createStub(QueryInterface::class);
        RequestContext::set(self::TX_STATES_KEY, [
            'probe' => new TransactionState($query, 1, false),
        ]);
        self::assertNull(DictionaryCacheNamespace::fingerprint(['ja_JP']));

        $before = DictionaryCacheNamespace::processMemoryStore()->getItemCount();
        $cache = [];
        $bag = &DictionaryCacheNamespace::localCache($cache, 1024, ['ja_JP']);
        $bag['fp|ja_JP|word'] = 'dirty';

        $again = &DictionaryCacheNamespace::localCache($cache, 1024, ['ja_JP']);
        self::assertArrayNotHasKey('fp|ja_JP|word', $again);
        self::assertSame([], $cache);
        self::assertSame($before, DictionaryCacheNamespace::processMemoryStore()->getItemCount());
        self::assertSame([], DictionaryCacheNamespace::processMemoryStore()->keysInBucket('ja_JP'));
    }

    public function testLocaleBucketPinProtectsKeysFromHardEviction(): void
    {
        $localeBag = [];
        DictionaryCacheNamespace::bindProcessBag('workerLocaleWords', $localeBag);

        $hot = &DictionaryCacheNamespace::localCache($localeBag, 1024, ['ja_JP']);
        $hot['fp|ja_JP|a'] = ['hello' => 'こんにちは'];
        $cold = &DictionaryCacheNamespace::localCache($localeBag, 1024, ['ko_KR']);
        $cold['fp|ko_KR|a'] = ['hello' => '안녕하세요'];

        DictionaryCacheNamespace::touchLocaleBucket('ja_JP');
        DictionaryCacheNamespace::touchLocaleBucket('ko_KR');
        DictionaryCacheNamespace::pinLocaleBucket('ja_JP');

        $result = DictionaryCacheNamespace::processMemoryReclaimable()->evict(1024 * 1024);
        self::assertFalse($result['skipped'] ?? true);

        self::assertNotEmpty(DictionaryCacheNamespace::processMemoryStore()->keysInBucket('ja_JP'));
        self::assertSame([], DictionaryCacheNamespace::processMemoryStore()->keysInBucket('ko_KR'));
        self::assertArrayHasKey('fp|ja_JP|a', $localeBag);
        self::assertArrayNotHasKey('fp|ko_KR|a', $localeBag);
        self::assertTrue(DictionaryCacheNamespace::isLocaleBucketPinned('ja_JP'));
    }

    public function testPressureEvictionKicksColdLocaleAndPurgesBagKeys(): void
    {
        $localeBag = [];
        DictionaryCacheNamespace::bindProcessBag('workerLocaleWords', $localeBag);

        foreach (['fr_FR', 'de_DE', 'es_ES'] as $locale) {
            $bag = &DictionaryCacheNamespace::localCache($localeBag, 1024, [$locale]);
            $bag['fp|' . $locale . '|heavy'] = ['x' => $locale];
            DictionaryCacheNamespace::touchLocaleBucket($locale);
        }

        // fr_FR is coldest; bump de/es heat so soft/hard prefer fr.
        DictionaryCacheNamespace::touchLocaleBucket('de_DE');
        DictionaryCacheNamespace::touchLocaleBucket('es_ES');

        $store = DictionaryCacheNamespace::processMemoryStore();
        self::assertNotSame([], $store->keysInBucket('fr_FR'));

        $reclaimable = DictionaryCacheNamespace::processMemoryReclaimable();
        $result = $reclaimable->evict(1);
        self::assertFalse($result['skipped'] ?? true);

        self::assertSame([], $store->keysInBucket('fr_FR'));
        self::assertArrayNotHasKey('fp|fr_FR|heavy', $localeBag);
        self::assertArrayHasKey('fp|es_ES|heavy', $localeBag);
    }

    public function testClearWorkerCachesClearsProcessMemoryStore(): void
    {
        $cache = [];
        $bag = &DictionaryCacheNamespace::localCache($cache, 1024, ['en_US']);
        $bag['fp|en_US|k'] = 'v';
        DictionaryCacheNamespace::touchLocaleBucket('en_US');
        self::assertGreaterThan(0, DictionaryCacheNamespace::processMemoryStore()->getItemCount());

        Parser::clearWorkerCaches();
        self::assertSame(0, DictionaryCacheNamespace::processMemoryStore()->getItemCount());
        self::assertSame([], DictionaryCacheNamespace::localeBucketResidents());
    }

    public function testHeavyLocaleResidentCapStillPurgesSeededParserBags(): void
    {
        self::assertSame(4, DictionaryCacheNamespace::heavyLocaleResidentMax());

        $localeCache = new ReflectionProperty(Parser::class, 'workerLocaleWordsCache');
        $globalCache = new ReflectionProperty(Parser::class, 'workerGlobalDictionaryWordsCache');

        $seed = static function (string $locale) use ($localeCache, $globalCache): void {
            $localeCache->setValue(null, \array_merge($localeCache->getValue(), [
                'fp|' . $locale . '|heavy' => ['hello' => 'world'],
            ]));
            $globalCache->setValue(null, \array_merge($globalCache->getValue(), [
                'fp|' . $locale . '|scope' => ['hello' => 'world'],
            ]));
        };

        $touch = new \ReflectionMethod(Parser::class, 'touchHeavyLocaleResident');
        foreach (['aa_AA', 'bb_BB', 'cc_CC', 'dd_DD', 'ee_EE'] as $locale) {
            $seed($locale);
            $touch->invoke(null, $locale);
        }

        self::assertSame(['bb_BB', 'cc_CC', 'dd_DD', 'ee_EE'], DictionaryCacheNamespace::localeBucketResidents());
        self::assertArrayNotHasKey('fp|aa_AA|heavy', $localeCache->getValue());
        self::assertArrayNotHasKey('fp|aa_AA|scope', $globalCache->getValue());
        self::assertSame([], DictionaryCacheNamespace::processMemoryStore()->keysInBucket('aa_AA'));
    }
}
