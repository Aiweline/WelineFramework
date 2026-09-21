<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Phrase\Parser;

final class ParserSetupUpgradeLightDictionaryTest extends TestCase
{
    protected function tearDown(): void
    {
        Parser::setSetupUpgradeLightDictionary(false);
        Parser::clearWorkerCaches();
        parent::tearDown();
    }

    public function testFlagDefaultsOffAndToggles(): void
    {
        self::assertFalse(Parser::isSetupUpgradeLightDictionary());
        Parser::setSetupUpgradeLightDictionary(true);
        self::assertTrue(Parser::isSetupUpgradeLightDictionary());
        Parser::setSetupUpgradeLightDictionary(false);
        self::assertFalse(Parser::isSetupUpgradeLightDictionary());
    }

    public function testClearWorkerCachesPreservesSetupUpgradeLightDictionaryFlag(): void
    {
        Parser::setSetupUpgradeLightDictionary(true);
        Parser::clearWorkerCaches();
        self::assertTrue(Parser::isSetupUpgradeLightDictionary());
        Parser::setSetupUpgradeLightDictionary(false);
    }

    public function testShouldSkipHeavyWhenSetupUpgradeLightDictionaryEnabled(): void
    {
        $method = new ReflectionMethod(Parser::class, 'shouldSkipHeavyLocaleDictionaryLoad');
        $method->setAccessible(true);

        Parser::setSetupUpgradeLightDictionary(false);
        // CLI / non-persistent default: do not skip merely for being CLI
        self::assertFalse((bool)$method->invoke(null));

        Parser::setSetupUpgradeLightDictionary(true);
        self::assertTrue((bool)$method->invoke(null));
    }

    public function testCliLoadLocaleWordsSkipsHeavyFileIncludeAndDoesNotHydrateDb(): void
    {
        $source = (string)file_get_contents((new \ReflectionClass(Parser::class))->getFileName());
        $start = strpos($source, 'private static function loadLocaleWords(');
        $end = strpos($source, 'private static function extractModuleWords(', $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($source, (int)$start, (int)$end - (int)$start);

        self::assertStringContainsString('shouldSkipHeavyLocaleDictionaryLoad()', $method);
        self::assertStringNotContainsString('loadGlobalDictionaryWords(', $method);
        self::assertStringNotContainsString('loadGlobalDictionaryScopeWords(', $method);
        self::assertStringContainsString('path_TRANSLATE_FILES_PATH', $method);
    }

    public function testSetupUpgradeExecuteWrapsLightDictionaryFlag(): void
    {
        $path = dirname(__DIR__, 3) . '/Setup/Console/Setup/Upgrade.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('Parser::setSetupUpgradeLightDictionary(true)', $src);
        self::assertStringContainsString('Parser::setSetupUpgradeLightDictionary(false)', $src);
        self::assertStringContainsString('executeUpgradeWithLocks(', $src);

        $executePos = strpos($src, 'public function execute(array $args = [], array $data = [])');
        self::assertNotFalse($executePos);
        $locksPos = strpos($src, 'private function executeUpgradeWithLocks(', $executePos);
        self::assertNotFalse($locksPos);
        $slice = substr($src, $executePos, $locksPos - $executePos);
        self::assertStringContainsString('Parser::setSetupUpgradeLightDictionary(true)', $slice);
        self::assertStringContainsString('try {', $slice);
        self::assertStringContainsString('return $this->executeUpgradeWithLocks($args, $data)', $slice);
        self::assertStringContainsString('finally {', $slice);
        self::assertStringContainsString('Parser::setSetupUpgradeLightDictionary(false)', $slice);
    }
}
