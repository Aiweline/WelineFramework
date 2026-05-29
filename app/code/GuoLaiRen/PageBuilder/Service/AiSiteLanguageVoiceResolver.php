<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * 从 theme_design / site_strategy 派生 CTA 词表与语气约束。
 */
final class AiSiteLanguageVoiceResolver
{
    /**
     * @return list<string>
     */
    private function foundationLexicon(string $locale): array
    {
        return $this->isCjkLocale($locale)
            ? ['立即体验', '了解更多', '马上开始', '免费试用', '立即下载', '联系我们']
            : ['Get Started', 'Learn More', 'Try Now', 'Download', 'Contact Us', 'Start Free'];
    }

    /**
     * @param array<string, mixed> $blueprintOrPlan
     * @return list<string>
     */
    public function resolveCtaLexicon(array $blueprintOrPlan, string $locale = ''): array
    {
        $themeDesign = \is_array($blueprintOrPlan['theme_design'] ?? null)
            ? $blueprintOrPlan['theme_design']
            : [];
        $siteStrategy = \is_array($blueprintOrPlan['site_strategy'] ?? null)
            ? $blueprintOrPlan['site_strategy']
            : (\is_array($blueprintOrPlan['plan_json']['site_strategy'] ?? null) ? $blueprintOrPlan['plan_json']['site_strategy'] : []);

        $primaryCta = \trim((string)($siteStrategy['primary_cta'] ?? ''));
        $ctaTone = \trim((string)($themeDesign['cta_tone'] ?? ''));

        $lexicon = [];
        if ($primaryCta !== '') {
            $lexicon[] = $primaryCta;
        }

        $isZh = $this->isCjkLocale($locale);
        $defaults = $this->foundationLexicon($locale);

        foreach ($defaults as $word) {
            if (\count($lexicon) >= 6) {
                break;
            }
            if (!\in_array($word, $lexicon, true)) {
                $lexicon[] = $word;
            }
        }

        if ($ctaTone !== '' && \count($lexicon) < 6) {
            $lexicon[] = $this->clipText($ctaTone, 24);
        }

        return \array_values(\array_unique(\array_filter(\array_map('strval', $lexicon))));
    }

    /**
     * @param array<string, mixed> $blueprintOrPlan
     * @return array<string, mixed>
     */
    public function buildLanguageContractExtension(array $blueprintOrPlan, string $locale): array
    {
        $locale = \trim($locale) !== '' ? \trim($locale) : 'zh_Hans_CN';
        $isZh = $this->isCjkLocale($locale);

        return [
            'cta_lexicon' => $this->resolveCtaLexicon($blueprintOrPlan, $locale),
            'punctuation_rules' => $isZh
                ? '中文页面使用全角标点；CTA 可省略句末标点。'
                : 'Use ASCII punctuation for Latin locales; sentence case for body copy.',
            'address_form' => $isZh ? '统一使用「您」，避免「你/您」混用。' : 'Use consistent second-person voice.',
            'title_case_rule' => $isZh ? '标题可使用短语式，不必英文 Title Case。' : 'Use sentence case unless brand requires title case.',
        ];
    }

    private function isCjkLocale(string $locale): bool
    {
        $locale = \strtolower(\str_replace('_', '-', $locale));

        return \str_starts_with($locale, 'zh')
            || \str_starts_with($locale, 'ja')
            || \str_starts_with($locale, 'ko');
    }

    private function clipText(string $text, int $max): string
    {
        $text = \trim($text);
        if (\mb_strlen($text) <= $max) {
            return $text;
        }

        return \mb_substr($text, 0, $max);
    }
}
