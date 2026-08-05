<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Service;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Weline\Cms\Service\PageTranslationPolicy;

final class PageTranslationPolicyTest extends TestCase
{
    public function testMissingTargetsAreLimitedToEmptySupportedLocales(): void
    {
        $policy = new PageTranslationPolicy();

        self::assertSame(
            ['ru_RU'],
            $policy->missingTargets(
                ['en_US', 'zh_Hans_CN', 'ru_RU', 'ru_RU'],
                [
                    'en_US' => 'About our team',
                    'zh_Hans_CN' => '关于我们',
                    'ru_RU' => '   ',
                    'de_DE' => '',
                ],
                'en_US',
            ),
        );
    }

    public function testMissingTargetsRequireANonEmptySourceTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PageTranslationPolicy())->missingTargets(
            ['en_US', 'zh_Hans_CN'],
            ['en_US' => '', 'zh_Hans_CN' => ''],
            'en_US',
        );
    }

    public function testMergeOnlyFillsStillMissingSupportedLocales(): void
    {
        $policy = new PageTranslationPolicy();

        self::assertSame(
            [
                'en_US' => 'About our team',
                'zh_Hans_CN' => '人工标题',
                'ru_RU' => 'Ручной заголовок',
                'fr_FR' => 'À propos de notre équipe',
            ],
            $policy->mergeMissing(
                [
                    'en_US' => 'About our team',
                    'zh_Hans_CN' => '人工标题',
                    'ru_RU' => 'Ручной заголовок',
                    'fr_FR' => '',
                ],
                [
                    'zh_Hans_CN' => '机器标题',
                    'ru_RU' => 'О нашей команде',
                    'fr_FR' => 'À propos de notre équipe',
                    'de_DE' => 'Über unser Team',
                ],
                ['en_US', 'zh_Hans_CN', 'ru_RU', 'fr_FR'],
                'en_US',
            ),
        );
    }
}
