<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;

final class I18nLocaleLanguageSelfNameTest extends TestCase
{
    private I18n $i18n;

    protected function setUp(): void
    {
        $this->i18n = ObjectManager::getInstance(I18n::class);
    }

    public function testSelfNameDiffersFromWebsiteDisplayNameOnEnglishSite(): void
    {
        $displayName = $this->i18n->getLocaleName('zh_Hans_CN', 'en_US');
        $selfName = $this->i18n->getLocaleLanguageSelfName('zh_Hans_CN');

        self::assertNotSame('', $displayName);
        self::assertNotSame('', $selfName);
        self::assertNotSame($displayName, $selfName);
        self::assertStringContainsString('简体', $selfName);
    }

    public function testEnglishSelfNameIsLanguageOnly(): void
    {
        $displayName = $this->i18n->getLocaleName('en_US', 'en_US');
        $selfName = $this->i18n->getLocaleLanguageSelfName('en_US');

        self::assertSame('English', $selfName);
        self::assertNotSame($displayName, $selfName);
    }
}
