<?php
declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Data;

use PHPUnit\Framework\TestCase;

final class HanfuR2EthnicEditorialQualityTest extends TestCase
{
    public function testAllLocalizedEthnicArticlesAreSubstantiveAndDiverse(): void
    {
        require_once dirname(__DIR__, 3) . '/data/hanfu-r2-ethnic-editorial.php';
        $profiles = require dirname(__DIR__, 3) . '/data/china-ethnic-groups.php';
        self::assertCount(56, $profiles);

        $bodies = [];
        $ledes = [];
        $signatures = [];
        foreach ($profiles as $profile) {
            foreach (['overview', 'occasion'] as $variant) {
                foreach (['zh_Hans_CN', 'en_US'] as $locale) {
                    $name = $locale === 'en_US' ? $profile['en'] : $profile['zh'];
                    $title = $name . ' ' . $variant . ' ' . $locale;
                    $article = \hanfuR2EthnicEditorial($profile, $variant, $title, $locale);
                    $key = $profile['code'] . '|' . $variant . '|' . $locale;
                    self::assertCount(7, $article['sections'], $key);
                    self::assertCount(5, $article['reviewed_facts'], $key);
                    $plain = $article['lede'];
                    $headings = [];
                    foreach ($article['sections'] as $section) {
                        $headings[] = $section['heading'];
                        $plain .= ' ' . $section['heading'] . ' ' . implode(' ', $section['paragraphs']);
                    }
                    $plain .= ' ' . implode(' ', $article['reviewed_facts']);
                    self::assertGreaterThanOrEqual(1200, mb_strlen($plain, 'UTF-8'), $key . ' is too thin');
                    foreach (['region', 'silhouette', 'fabric', 'occasion', 'motif'] as $fact) {
                        $field = $fact . ($locale === 'en_US' ? '_en' : '');
                        self::assertStringContainsString($profile[$field], $plain, $key . ' misses ' . $field);
                    }
                    self::assertStringNotContainsString('documents a reading path', $plain, $key);
                    self::assertStringNotContainsString('建立一条可核查的阅读路径', $plain, $key);
                    $bodies[$key] = $plain;
                    $ledes[$key] = $article['lede'];
                    $signatures[$key] = implode('|', $headings);
                }
            }
        }

        self::assertCount(224, $bodies);
        self::assertCount(224, array_unique($bodies));
        self::assertCount(224, array_unique($ledes));
        self::assertGreaterThanOrEqual(64, count(array_unique($signatures)));

        foreach ($profiles as $profile) {
            foreach (['zh_Hans_CN', 'en_US'] as $locale) {
                similar_text(
                    $bodies[$profile['code'] . '|overview|' . $locale],
                    $bodies[$profile['code'] . '|occasion|' . $locale],
                    $percent
                );
                self::assertLessThan(75.0, $percent, $profile['code'] . ' pair remains too similar');
            }
        }
    }
}
