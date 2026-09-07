<?php
declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Data;

use PHPUnit\Framework\TestCase;

final class HanfuR2EthnicEditorialQualityTest extends TestCase
{
    private function normalized(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string)preg_replace('#https?://\S+|\[[^\]]+\]#u', '', $value);
        $value = (string)preg_replace('/[“”‘’—–\p{P}\p{Z}]+/u', ' ', $value);
        return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    }

    public function testAllLocalizedEthnicArticlesAreSubstantiveAndDiverse(): void
    {
        require_once dirname(__DIR__, 3) . '/data/hanfu-r2-ethnic-editorial.php';
        $profiles = require dirname(__DIR__, 3) . '/data/china-ethnic-groups.php';
        self::assertCount(56, $profiles);

        $bodies = [];
        $ledes = [];
        $signatures = [];
        $paragraphsByLocale = ['zh_Hans_CN' => [], 'en_US' => []];
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
                    $paragraphsByLocale[$locale][] = $article['lede'];
                    $headings = [];
                    foreach ($article['sections'] as $section) {
                        $headings[] = $section['heading'];
                        if ($variant === 'occasion') {
                            self::assertFalse(str_starts_with($section['heading'], 'Occasion: '), $key . ':prefixed-heading');
                            self::assertFalse(str_starts_with($section['heading'], '场合：'), $key . ':prefixed-heading');
                        }
                        $plain .= ' ' . $section['heading'] . ' ' . implode(' ', $section['paragraphs']);
                        foreach ($section['paragraphs'] as $paragraph) {
                            $paragraphsByLocale[$locale][] = $paragraph;
                        }
                    }
                    $plain .= ' ' . implode(' ', $article['reviewed_facts']);
                    if ($locale === 'en_US') {
                        self::assertGreaterThanOrEqual(700, count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: []), $key . ' is too thin');
                    } else {
                        self::assertGreaterThanOrEqual(1200, preg_match_all('/\p{Han}/u', $plain), $key . ' is too thin');
                    }
                    foreach (['community', 'region', 'silhouette', 'fabric', 'occasion', 'inference_limit'] as $fact) {
                        $field = $fact . ($locale === 'en_US' ? '_en' : '');
                        self::assertStringContainsString($profile[$field], $plain, $key . ' misses ' . $field);
                    }
                    $forbidden = $locale === 'en_US'
                        ? '/plain-language category|evidence brief|evidence card|profile definition|required observation|editor(?:’|\x{2019}|\x{27})s conclusion|before any recommendation is published|Here, that conclusion applies specifically|evidence fields|publishable record|occasion diagnostic|working method into/iu'
                        : '/先用普通语言写清|把“?缺少证据”?写进结论|(?:可追溯(?:的)?)?证据卡|唯一可执行的下一步|该主题的定义是|必须核对的观察是|卖家陈述与编辑结论|在发布任何建议前|在本文中[，,]这一结论只针对|围绕“[^”]+”[：:]|证据字段|可发布记录|场合诊断|发布“[^”]+”记录|核查清单包括|应把工作方法整理/u';
                    self::assertDoesNotMatchRegularExpression($forbidden, $plain, $key . ':reader voice');
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
        self::assertGreaterThanOrEqual(28, count(array_unique($signatures)));
        self::assertStringNotContainsString('hanfuR2EthnicProfileAnchor', (string)file_get_contents(dirname(__DIR__, 3) . '/data/hanfu-r2-ethnic-editorial.php'));

        foreach (['zh_Hans_CN', 'en_US'] as $locale) {
            $paragraphs = [];
            foreach ($paragraphsByLocale[$locale] as $paragraph) {
                $normalized = $this->normalized($paragraph);
                if (mb_strlen($normalized, 'UTF-8') >= 36) {
                    $paragraphs[$normalized] = ($paragraphs[$normalized] ?? 0) + 1;
                }
            }
            self::assertLessThanOrEqual(4, max($paragraphs), 'normalized paragraph reuse exceeds four for ' . $locale);
        }

        foreach ($profiles as $profile) {
            foreach (['zh_Hans_CN', 'en_US'] as $locale) {
                similar_text(
                    mb_substr($bodies[$profile['code'] . '|overview|' . $locale], 0, 2200, 'UTF-8'),
                    mb_substr($bodies[$profile['code'] . '|occasion|' . $locale], 0, 2200, 'UTF-8'),
                    $percent
                );
                self::assertLessThan(75.0, $percent, $profile['code'] . ' pair remains too similar');
            }
        }
    }
}
