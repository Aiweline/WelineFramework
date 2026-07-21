<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locals;
use Weline\I18n\Service\ActiveLocaleCodeProvider;

class ActiveLocaleCodeProviderTest extends TestCase
{
    public function testReturnsInstalledActiveLocaleMapMergedFromLocalsAndLocale(): void
    {
        $locals = $this->getMockBuilder(Locals::class)
            ->disableOriginalConstructor()
            ->addMethods(['clearQuery', 'where', 'select', 'fetchArray'])
            ->getMock();
        $locals->expects($this->once())
            ->method('clearQuery')
            ->willReturnSelf();
        $locals->expects($this->exactly(2))
            ->method('where')
            ->willReturnSelf();
        $locals->expects($this->once())
            ->method('select')
            ->with('code')
            ->willReturnSelf();
        $locals->expects($this->once())
            ->method('fetchArray')
            ->willReturn([
                ['code' => 'zh_Hans_CN'],
                ['unexpected' => 'ignored'],
            ]);

        $locale = $this->getMockBuilder(Locale::class)
            ->disableOriginalConstructor()
            ->addMethods(['clearQuery', 'where', 'select', 'fetchArray'])
            ->getMock();
        $locale->expects($this->once())
            ->method('clearQuery')
            ->willReturnSelf();
        $locale->expects($this->exactly(2))
            ->method('where')
            ->willReturnSelf();
        $locale->expects($this->once())
            ->method('select')
            ->with('code')
            ->willReturnSelf();
        $locale->expects($this->once())
            ->method('fetchArray')
            ->willReturn([
                ['code' => 'zh_Hans_CN'],
                ['code' => 'en_US'],
                ['code' => 'ja_JP'],
            ]);

        $provider = new ActiveLocaleCodeProvider($locals, $locale);

        self::assertSame(
            [
                'zh_Hans_CN' => true,
                'zh_hans_cn' => true,
                'en_US' => true,
                'en_us' => true,
                'ja_JP' => true,
                'ja_jp' => true,
            ],
            $provider->getInstalledActiveCodeMap()
        );
        self::assertSame(['zh_Hans_CN', 'en_US', 'ja_JP'], $provider->getInstalledActiveCodes());
    }
}
