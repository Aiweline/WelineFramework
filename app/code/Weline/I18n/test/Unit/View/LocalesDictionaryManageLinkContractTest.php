<?php

declare(strict_types=1);

namespace Weline\I18n\test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 国家「区域」弹窗/列表的「词典管理」必须打开正式词典页并携带 locale_code，
 * 禁止残留 countries/locale/words 旧入口。
 */
final class LocalesDictionaryManageLinkContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
        }
    }

    public function testPanelAndListLinkToDictionaryWithLocaleCode(): void
    {
        $panel = $this->read('app/code/Weline/I18n/view/templates/Backend/Countries/Locales/panel.phtml');
        $list = $this->read('app/code/Weline/I18n/view/templates/Backend/Countries/Locales/getIndex.phtml');
        $queryProvider = $this->read(
            'app/code/Weline/I18n/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php'
        );

        foreach ([$panel, $list] as $source) {
            self::assertStringContainsString(
                "@backend-url('*/backend/dictionary')?locale_code=",
                $source
            );
            self::assertStringContainsString('country_code=', $source);
            self::assertStringNotContainsString(
                "@backend-url('*/backend/countries/locale/words')",
                $source
            );
            self::assertStringNotContainsString('countries/locale/words', $source);
        }

        self::assertFileDoesNotExist(
            BP . 'app/code/Weline/I18n/Controller/Backend/Countries/Locale/Words.php'
        );
        self::assertFileDoesNotExist(
            BP . 'app/code/Weline/I18n/view/templates/Backend/Countries/Locale/Words/index.phtml'
        );
        self::assertStringNotContainsString('word-collect', $queryProvider);
        self::assertStringNotContainsString(
            'Countries\\Locale\\Words::class',
            $queryProvider
        );
    }

    private function read(string $relativePath): string
    {
        $path = BP . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
