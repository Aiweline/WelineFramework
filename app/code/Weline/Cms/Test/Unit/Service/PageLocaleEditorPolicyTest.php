<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cms\Service\PageLocaleService;

final class PageLocaleEditorPolicyTest extends TestCase
{
    public function testEditorLocalePrefersLegalRequestThenEnglishThenSource(): void
    {
        $service = new PageLocaleService(queryExecutor: static fn(): array => []);
        $supported = ['zh_Hans_CN', 'en_US', 'ru_RU'];

        self::assertSame('ru_RU', $service->resolveEditorLocale($supported, 'ru-ru', 'zh_Hans_CN'));
        self::assertSame('en_US', $service->resolveEditorLocale($supported, 'de_DE', 'zh_Hans_CN'));
        self::assertSame('fr_FR', $service->resolveEditorLocale(['zh_Hans_CN', 'fr_FR'], '', 'fr_FR'));
    }

    public function testSubmittedTitleMapNormalizesJsonAndRejectsUnsupportedLocale(): void
    {
        $service = new PageLocaleService(queryExecutor: static fn(): array => []);

        self::assertSame(
            ['en_US' => 'About', 'ru_RU' => 'О нас'],
            $service->normalizeSubmittedTitles(
                '{"en-us":" About ","ru_RU":" О нас ","zh_Hans_CN":"   "}',
                ['en_US', 'ru_RU', 'zh_Hans_CN'],
            ),
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->normalizeSubmittedTitles(['de_DE' => 'Über uns'], ['en_US', 'ru_RU']);
    }
}
