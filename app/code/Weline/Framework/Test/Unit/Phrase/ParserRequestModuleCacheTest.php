<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class ParserRequestModuleCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (Context::hasCurrent()) {
            Context::leave();
        }
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        RequestContext::init();
        State::setRequestLanguageOverride('en_US');
        WelineEnv::setLang('en_US');
        WelineEnv::setCurrency('CNY');
        $_SERVER['WELINE_USER_LANG'] = 'en_US';
        $_SERVER['WELINE_USER_CURRENCY'] = 'CNY';
        Parser::clearWorkerCaches();
    }

    protected function tearDown(): void
    {
        Parser::clearWorkerCaches();
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Runtime::resetModeCache();

        parent::tearDown();
    }

    public function testLayeredWordsRefreshWhenHookAddsRequestModuleInSameWlsRequest(): void
    {
        $request = ObjectManager::getInstance(Request::class);
        $request->setModules(['Weline_Customer']);

        self::assertSame('en_US', State::getLangLocal());

        $initialModules = $this->currentLayerModules();
        self::assertContains('Weline_Customer', $initialModules);
        self::assertNotContains('WeShop_Affiliate', $initialModules);

        $request->addModule('WeShop_Affiliate');

        $updatedModules = $this->currentLayerModules();
        self::assertContains('Weline_Customer', $updatedModules);
        self::assertContains('WeShop_Affiliate', $updatedModules);
    }

    public function testPersistentLocaleLayerUsesBatchGlobalDictionaryWhenMemoryAllows(): void
    {
        $source = (string)file_get_contents((new \ReflectionClass(Parser::class))->getFileName());
        $start = strpos($source, 'private static function loadLocaleWords(');
        $end = strpos($source, 'private static function extractModuleWords(', $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($source, (int)$start, (int)$end - (int)$start);

        self::assertStringContainsString('shouldSkipHeavyLocaleDictionaryLoad()', $method);
        self::assertStringNotContainsString('loadGlobalDictionaryScopeWords(', $method);
        self::assertStringNotContainsString('loadGlobalDictionaryWords(', $method);
        self::assertStringContainsString('path_TRANSLATE_FILES_PATH', $method);
    }

    public function testPersistentGlobalDictionaryBatchExtendsOnlyForNewModules(): void
    {
        $source = (string)file_get_contents((new \ReflectionClass(Parser::class))->getFileName());
        $start = strpos($source, 'private static function loadGlobalDictionaryScopeWords(');
        $end = strpos($source, 'private static function getSharedPhraseCachePool(', $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($source, (int)$start, (int)$end - (int)$start);

        self::assertStringContainsString('$workerGlobalDictionaryLoadedModules', $method);
        self::assertStringContainsString('\\array_diff($modules, $loadedModules)', $method);
        self::assertStringContainsString('loadGlobalDictionaryWordsFromDatabase($lang, $queryModules)', $method);
        self::assertStringNotContainsString("self::\$workerGlobalDictionaryWordsCache['locale|' . \$lang]", $method);
    }

    /**
     * @return list<string>
     */
    private function currentLayerModules(): array
    {
        $method = new ReflectionMethod(Parser::class, 'getCurrentLayeredWords');
        $method->setAccessible(true);
        $layers = (array)$method->invoke(null);

        return array_values(array_map('strval', (array)($layers['modules'] ?? [])));
    }
}
