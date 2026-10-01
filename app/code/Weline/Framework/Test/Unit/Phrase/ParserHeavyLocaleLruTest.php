<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\Phrase\Parser;

final class ParserHeavyLocaleLruTest extends TestCase
{
    protected function setUp(): void
    {
        Parser::clearWorkerCaches();
    }

    protected function tearDown(): void
    {
        Parser::clearWorkerCaches();
    }

    public function testHeavyLocaleResidentCapKeepsOnlyOneLocaleBag(): void
    {
        self::assertSame(1, $this->invoke('heavyLocaleResidentMax'));

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

        foreach (['aa_AA', 'bb_BB', 'cc_CC'] as $locale) {
            $seed($locale);
            $this->invoke('touchHeavyLocaleResident', $locale);
        }

        self::assertSame(['cc_CC'], $this->invoke('heavyLocaleResidents'));
        self::assertArrayNotHasKey('fp|aa_AA|heavy', $localeCache->getValue());
        self::assertArrayNotHasKey('fp|bb_BB|heavy', $localeCache->getValue());
        self::assertArrayHasKey('fp|cc_CC|heavy', $localeCache->getValue());
        self::assertArrayHasKey('fp|cc_CC|scope', $globalCache->getValue());

        $seed('dd_DD');
        $this->invoke('touchHeavyLocaleResident', 'dd_DD');
        self::assertSame(['dd_DD'], $this->invoke('heavyLocaleResidents'));
        self::assertArrayNotHasKey('fp|cc_CC|heavy', $localeCache->getValue());
        self::assertArrayHasKey('fp|dd_DD|heavy', $localeCache->getValue());
    }

    public function testClearWorkerCachesResetsHeavyLocaleLru(): void
    {
        $this->invoke('touchHeavyLocaleResident', 'zh_Hans_CN');
        $this->invoke('touchHeavyLocaleResident', 'en_US');
        self::assertNotSame([], $this->invoke('heavyLocaleResidents'));
        Parser::clearWorkerCaches();
        self::assertSame([], $this->invoke('heavyLocaleResidents'));
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod(Parser::class, $method);

        return $reflection->invoke(null, ...$args);
    }
}
