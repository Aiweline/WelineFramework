<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\I18n\Config\Reader;
use Weline\I18n\Model\I18n;

final class CollectedWordsAccessorTest extends TestCase
{
    public function testGetCollectedWordsUsesDefaultLanguageDictionary(): void
    {
        $reader = $this->createMock(Reader::class);
        $model = $this->getMockBuilder(I18n::class)
            ->setConstructorArgs([$reader])
            ->onlyMethods(['getLocalWords'])
            ->getMock();

        $expected = [
            'Hello' => 'Hello',
            'World' => 'World',
        ];

        $model->expects(self::once())
            ->method('getLocalWords')
            ->with(Env::default_LANGUAGE_CODE)
            ->willReturn($expected);

        self::assertSame($expected, $model->getCollectedWords());
    }
}
