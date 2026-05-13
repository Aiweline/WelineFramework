<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Model\Locals;
use Weline\I18n\Service\ActiveLocaleCodeProvider;

class ActiveLocaleCodeProviderTest extends TestCase
{
    public function testReturnsInstalledActiveLocaleMapAndUsesStringSelect(): void
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
                ['code' => 'en_US'],
                ['unexpected' => 'ignored'],
            ]);

        $provider = new ActiveLocaleCodeProvider($locals);

        self::assertSame(
            [
                'zh_Hans_CN' => true,
                'zh_hans_cn' => true,
                'en_US' => true,
                'en_us' => true,
            ],
            $provider->getInstalledActiveCodeMap()
        );
        self::assertSame(['zh_Hans_CN', 'en_US'], $provider->getInstalledActiveCodes());
    }
}
