<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locals;
use Weline\I18n\Service\ActiveLocaleCodeProvider;

class ActiveLocaleCodeProviderTest extends TestCase
{
    public function testMergesLocaleInstallRegistryWithLocalsRows(): void
    {
        $locale = $this->mockQueryModel(Locale::class, [
            ['code' => 'zh_Hans_CN'],
            ['code' => 'en_US'],
            ['code' => 'ja_JP'],
        ]);
        $locals = $this->mockQueryModel(Locals::class, [
            ['code' => 'zh_Hans_CN'],
            ['unexpected' => 'ignored'],
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

    public function testResetClearsMemoizedCodes(): void
    {
        $locale = $this->getMockBuilder(Locale::class)
            ->disableOriginalConstructor()
            ->addMethods(['clearQuery', 'where', 'select', 'fetchArray'])
            ->getMock();
        $locale->expects($this->exactly(2))
            ->method('clearQuery')
            ->willReturnSelf();
        $locale->expects($this->exactly(4))
            ->method('where')
            ->willReturnSelf();
        $locale->expects($this->exactly(2))
            ->method('select')
            ->with('code')
            ->willReturnSelf();
        $locale->expects($this->exactly(2))
            ->method('fetchArray')
            ->willReturnOnConsecutiveCalls(
                [['code' => 'zh_Hans_CN']],
                [['code' => 'en_US'], ['code' => 'zh_Hans_CN']]
            );

        $locals = $this->getMockBuilder(Locals::class)
            ->disableOriginalConstructor()
            ->addMethods(['clearQuery', 'where', 'select', 'fetchArray'])
            ->getMock();
        $locals->expects($this->exactly(2))
            ->method('clearQuery')
            ->willReturnSelf();
        $locals->expects($this->exactly(4))
            ->method('where')
            ->willReturnSelf();
        $locals->expects($this->exactly(2))
            ->method('select')
            ->with('code')
            ->willReturnSelf();
        $locals->expects($this->exactly(2))
            ->method('fetchArray')
            ->willReturn([]);

        $provider = new ActiveLocaleCodeProvider($locals, $locale);
        self::assertSame(['zh_Hans_CN'], $provider->getInstalledActiveCodes());
        $provider->reset();
        self::assertSame(['en_US', 'zh_Hans_CN'], $provider->getInstalledActiveCodes());
    }

    /**
     * @param class-string $class
     * @param list<array<string, mixed>> $rows
     */
    private function mockQueryModel(string $class, array $rows): object
    {
        $model = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->addMethods(['clearQuery', 'where', 'select', 'fetchArray'])
            ->getMock();
        $model->expects($this->once())
            ->method('clearQuery')
            ->willReturnSelf();
        $model->expects($this->exactly(2))
            ->method('where')
            ->willReturnSelf();
        $model->expects($this->once())
            ->method('select')
            ->with('code')
            ->willReturnSelf();
        $model->expects($this->once())
            ->method('fetchArray')
            ->willReturn($rows);

        return $model;
    }
}
