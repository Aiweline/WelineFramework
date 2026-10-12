<?php
declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Service\DictionaryAreaPolicy;
use Weline\I18n\Service\LocaleAreaDictionaryLoader;

final class LocaleAreaDictionaryLoaderContractTest extends TestCase
{
    public function testDetectRequestAreaPrefersRequestContextBackend(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LocaleAreaDictionaryLoader.php'
        );
        self::assertStringContainsString('RequestContext::isBackendArea()', $src);
        self::assertStringContainsString('allowLegacyUnion', $src);

        if (!RequestContext::isInitialized()) {
            RequestContext::init();
        }
        $prevArea = RequestContext::getWelineArea();
        try {
            RequestContext::setWelineArea(RequestContext::AREA_BACKEND);
            self::assertSame(
                DictionaryAreaPolicy::AREA_BACKEND,
                LocaleAreaDictionaryLoader::detectRequestArea()
            );
            RequestContext::setWelineArea(RequestContext::AREA_FRONTEND);
            self::assertSame(
                DictionaryAreaPolicy::AREA_FRONTEND,
                LocaleAreaDictionaryLoader::detectRequestArea()
            );
        } finally {
            RequestContext::setWelineArea($prevArea !== '' ? $prevArea : RequestContext::AREA_FRONTEND);
        }
    }

    public function testLoadMergedWorkerForbidsLegacyWholeBag(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LocaleAreaDictionaryLoader.php'
        );
        self::assertStringContainsString('Persistent Worker 禁止整袋', $src);
        self::assertMatchesRegularExpression(
            '/if \(\$merged === \[\] && \$allowLegacyUnion\)/',
            $src
        );
    }
}
