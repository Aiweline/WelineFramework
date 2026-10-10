<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Phrase\DictionaryCacheNamespace;

/**
 * Soft compact must discover locales from bound bag keys even when Store/LRU
 * only retains a pinned request locale (keysInBucket empty for bag-only locales).
 */
final class PhraseBagLocaleSoftReclaimContractTest extends TestCase
{
    protected function tearDown(): void
    {
        DictionaryCacheNamespace::clearProcessMemoryStore();
        parent::tearDown();
    }

    public function testSoftCompactEvictsBagOnlyColdLocalesWhenStoreEmpty(): void
    {
        DictionaryCacheNamespace::clearProcessMemoryStore();

        $bag = [
            'fp|layered|fr_FR|word' => 'bonjour',
            'fp|layered|de_DE|word' => 'hallo',
            'fp|layered|it_IT|word' => 'ciao',
        ];
        DictionaryCacheNamespace::bindProcessBag('testLayered', $bag);

        DictionaryCacheNamespace::touchLocaleBucket('bn_BD');
        DictionaryCacheNamespace::pinLocaleBucket('bn_BD');

        self::assertSame(['bn_BD'], DictionaryCacheNamespace::localeBucketResidents());
        self::assertContains('fr_FR', DictionaryCacheNamespace::localesPresentInBoundBags());
        self::assertTrue(DictionaryCacheNamespace::localeHasBoundBagKeys('fr_FR'));
        self::assertSame(
            [],
            DictionaryCacheNamespace::processMemoryStore()->keysInBucket('fr_FR'),
        );

        $result = DictionaryCacheNamespace::processMemoryReclaimable()->compact();
        self::assertEmpty($result['skipped'] ?? null);

        $remainingLocales = [];
        foreach (\array_keys($bag) as $key) {
            if (\str_contains((string)$key, '|fr_FR|')) {
                $remainingLocales['fr_FR'] = true;
            }
            if (\str_contains((string)$key, '|de_DE|')) {
                $remainingLocales['de_DE'] = true;
            }
            if (\str_contains((string)$key, '|it_IT|')) {
                $remainingLocales['it_IT'] = true;
            }
        }

        self::assertLessThan(
            3,
            \count($remainingLocales),
            'soft reclaim must drop at least one bag-only cold locale when Store is empty',
        );
        self::assertTrue(
            DictionaryCacheNamespace::isLocaleBucketPinned('bn_BD'),
            'pinned request locale must survive soft compact',
        );

        DictionaryCacheNamespace::unpinLocaleBucket('bn_BD');
    }
}
