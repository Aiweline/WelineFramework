<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Service\ClientI18nBagPublisher;
use Weline\I18n\Service\ClientI18nSeedPhrases;
use Weline\I18n\Service\ClientWordSetStore;
use Weline\I18n\Service\DictionaryAreaPolicy;

final class ClientI18nBagPublisherContractTest extends TestCase
{
    public function testSeedPhrasesIncludeWaitTipAndChrome(): void
    {
        $seeds = ClientI18nSeedPhrases::all();
        self::assertContains('请稍等片刻...', $seeds);
        self::assertContains('关闭', $seeds);
        self::assertContains('确定', $seeds);
        self::assertContains('取消', $seeds);
        self::assertContains('请重试', $seeds);
    }

    public function testWordSetStoreMergesSeeds(): void
    {
        $ok = ClientWordSetStore::save(DictionaryAreaPolicy::AREA_FRONTEND, ['自定义词' => '自定义词']);
        self::assertTrue($ok);
        $words = ClientWordSetStore::load(DictionaryAreaPolicy::AREA_FRONTEND);
        self::assertContains('自定义词', $words);
        self::assertContains('请稍等片刻...', $words);
        self::assertContains('关闭', $words);
    }

    public function testPublisherWritesInstallScriptWithSetDictionary(): void
    {
        ClientWordSetStore::save(DictionaryAreaPolicy::AREA_FRONTEND, ['请稍等片刻...' => '请稍等片刻...']);
        $publisher = new ClientI18nBagPublisher();
        self::assertTrue($publisher->publishAreaLocale(DictionaryAreaPolicy::AREA_FRONTEND, 'en_US'));
        $path = ClientI18nBagPublisher::bagAbsolutePath(DictionaryAreaPolicy::AREA_FRONTEND, 'en_US');
        self::assertFileExists($path);
        $js = (string)file_get_contents($path);
        self::assertStringStartsWith('(function(w){', $js);
        self::assertStringContainsString('setDictionary', $js);
        self::assertStringContainsString('请稍等片刻...', $js);
        $meta = ClientI18nBagPublisher::resolveFooterScript(DictionaryAreaPolicy::AREA_FRONTEND, 'en_US');
        self::assertIsArray($meta);
        self::assertStringContainsString('i18n-client/frontend/en_US.js', (string)$meta['url']);
        self::assertNotSame('', (string)$meta['sha']);
    }

    public function testResourceCompilerNoLongerAppendsTranslationCode(): void
    {
        $path = dirname(__DIR__, 3) . '/Observer/ResourceCompiler.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('ClientWordSetStore::save', $src);
        self::assertStringNotContainsString('generateTranslationCode($translations)', $src);
        self::assertStringContainsString('paths only', $src);
    }

    public function testFrontendHeadNoLongerEmbedsDictionary(): void
    {
        $path = BP . 'app/code/Weline/Frontend/view/blocks/header/base.phtml';
        $src = (string)file_get_contents($path);
        self::assertStringNotContainsString("'dictionary' => \$i18nWords", $src);
        self::assertStringNotContainsString("'i18n' => \$i18nWords", $src);
        self::assertStringContainsString("'area' => 'frontend'", $src);
    }

    public function testBackendHeadNoLongerEmbedsDictionary(): void
    {
        $path = BP . 'app/code/Weline/Backend/view/blocks/header/base.phtml';
        $src = (string)file_get_contents($path);
        self::assertStringNotContainsString("'dictionary' => \$i18nWords", $src);
        self::assertStringNotContainsString("'i18n' => \$i18nWords", $src);
    }

    public function testAiTranslationPublisherHooksClientBag(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/AiTranslationPublisher.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('ClientI18nBagPublisher::class', $src);
        self::assertStringContainsString('publishForLocale', $src);
    }
}
