<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\I18nAiTranslationAdapter;

/**
 * Fill inquiry form translation trees from default_locale via I18n AI chain.
 */
final class InquiryTranslationAiService
{
    public function __construct(
        private readonly InquiryTranslationTree $tree,
        private readonly I18nAiTranslationAdapter $adapter,
        private readonly AiTranslationConfig $aiConfig,
    ) {
    }

    /**
     * @param array<string, mixed> $translations
     * @param list<string>|null $targetLocales
     * @return array{
     *   success: bool,
     *   message: string,
     *   translations: array<string, mixed>,
     *   data: array<string, mixed>
     * }
     */
    public function fillFromDefaultLocale(
        array $translations,
        string $defaultLocale,
        bool $force = false,
        ?array $targetLocales = null,
    ): array {
        $defaultLocale = $this->normalizeLocale($defaultLocale);
        if ($defaultLocale === '') {
            return $this->fail((string)__('默认语言不能为空'));
        }

        $sourceCopy = $translations[$defaultLocale] ?? null;
        if (!is_array($sourceCopy)) {
            return $this->fail((string)__('默认语言翻译为空，请先填写母本文案'));
        }

        $sourceLeaves = $this->tree->flatten($sourceCopy);
        if ($sourceLeaves === []) {
            return $this->fail((string)__('默认语言没有可翻译的文案叶子'));
        }

        $targets = $this->resolveTargetLocales($defaultLocale, $targetLocales, $translations);
        if ($targets === []) {
            return $this->fail((string)__('没有可填充的目标语言。请配置网站关联语言并在 I18n AI 翻译中启用目标语言。'));
        }

        $uniqueTexts = array_values(array_unique(array_values($sourceLeaves)));
        $out = $translations;
        $translatedLeaves = 0;
        $skippedLeaves = 0;
        $filledLocales = [];
        $errors = [];

        foreach ($targets as $targetLocale) {
            $existing = is_array($out[$targetLocale] ?? null) ? $out[$targetLocale] : [];
            $toTranslate = [];
            $pathForText = [];

            foreach ($sourceLeaves as $path => $text) {
                $current = $this->tree->getByPath($existing, $path);
                if (!$force && is_string($current) && trim($current) !== '') {
                    $skippedLeaves++;
                    continue;
                }
                $toTranslate[] = $text;
                $pathForText[$path] = $text;
            }

            if ($pathForText === []) {
                continue;
            }

            $batch = $this->adapter->translateBatch(
                array_values(array_unique($toTranslate)),
                $defaultLocale,
                $targetLocale,
                $this->aiConfig->getStrategy($targetLocale),
            );
            if (!$batch['success']) {
                $errors = array_merge($errors, $batch['errors']);
                continue;
            }

            $map = is_array($batch['translations'] ?? null) ? $batch['translations'] : [];
            $leafWrites = [];
            foreach ($pathForText as $path => $sourceText) {
                $translated = trim((string)($map[$sourceText] ?? ''));
                if ($translated === '') {
                    continue;
                }
                $leafWrites[$path] = $translated;
            }

            if ($leafWrites === []) {
                continue;
            }

            $out[$targetLocale] = $this->tree->mergeLeaves($existing, $leafWrites, true);
            $translatedLeaves += count($leafWrites);
            $filledLocales[] = $targetLocale;
        }

        if ($translatedLeaves === 0) {
            if ($skippedLeaves > 0 && !$force) {
                return [
                    'success' => true,
                    'message' => (string)__('目标语言文案均已存在，未覆盖。如需重翻请勾选强制。'),
                    'translations' => $out,
                    'data' => [
                        'translated_leaves' => 0,
                        'skipped_leaves' => $skippedLeaves,
                        'locales' => [],
                        'source_locale' => $defaultLocale,
                        'errors' => array_values(array_unique($errors)),
                    ],
                ];
            }

            $message = $errors !== []
                ? (string)__('AI 翻译调用失败')
                : (string)__('AI 翻译未返回可用结果');

            return $this->fail($message, $out, [
                'translated_leaves' => 0,
                'skipped_leaves' => $skippedLeaves,
                'locales' => [],
                'source_locale' => $defaultLocale,
                'errors' => array_values(array_unique($errors)),
            ]);
        }

        return [
            'success' => true,
            'message' => (string)__(
                '已填充 %{1} 条文案到 %{2} 个语言',
                [(string)$translatedLeaves, (string)count($filledLocales)],
            ),
            'translations' => $out,
            'data' => [
                'translated_leaves' => $translatedLeaves,
                'skipped_leaves' => $skippedLeaves,
                'locales' => $filledLocales,
                'source_locale' => $defaultLocale,
                'unique_source_texts' => count($uniqueTexts),
                'errors' => array_values(array_unique($errors)),
            ],
        ];
    }

    /**
     * @param list<string>|null $override
     * @param array<string, mixed> $translations
     * @return list<string>
     */
    private function resolveTargetLocales(string $sourceLocale, ?array $override, array $translations): array
    {
        if (is_array($override) && $override !== []) {
            $codes = $this->normalizeLocaleList($override);
        } else {
            $site = $this->fetchWebsiteLanguageCodes();
            $enabled = $this->aiConfig->getEnabledLocaleCodes();
            if ($enabled === []) {
                // Fall back to installed-active ∩ site, still allowing translate attempt.
                $enabled = $this->aiConfig->getInstalledActiveLocaleCodes();
            }
            $enabledMap = [];
            foreach ($enabled as $code) {
                $n = $this->normalizeLocale((string)$code);
                if ($n !== '') {
                    $enabledMap[strtolower($n)] = $n;
                }
            }

            $codes = [];
            $pool = $site !== [] ? $site : array_keys($enabledMap);
            // Also include locales already present in translations payload (except source).
            foreach (array_keys($translations) as $existingLocale) {
                $pool[] = (string)$existingLocale;
            }
            foreach ($this->normalizeLocaleList($pool) as $code) {
                if (isset($enabledMap[strtolower($code)])) {
                    $codes[] = $enabledMap[strtolower($code)];
                }
            }
            $codes = array_values(array_unique($codes));
        }

        return array_values(array_filter(
            $codes,
            static fn(string $code): bool => strcasecmp($code, $sourceLocale) !== 0,
        ));
    }

    /**
     * @return list<string>
     */
    private function fetchWebsiteLanguageCodes(): array
    {
        try {
            if (!function_exists('w_query')) {
                return [];
            }
            $websiteId = 0;
            try {
                $id = \w_query('websites', 'getCurrentWebsiteId', []);
                $websiteId = (int)$id;
            } catch (\Throwable) {
                $websiteId = 0;
            }
            $result = \w_query('websites', 'getWebsiteLanguageCodes', ['website_id' => $websiteId]);
            return is_array($result) ? $this->normalizeLocaleList($result) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param iterable<mixed> $codes
     * @return list<string>
     */
    private function normalizeLocaleList(iterable $codes): array
    {
        $out = [];
        $seen = [];
        foreach ($codes as $code) {
            if (is_array($code) && isset($code['code'])) {
                $code = $code['code'];
            }
            if (!is_scalar($code)) {
                continue;
            }
            $normalized = $this->normalizeLocale((string)$code);
            if ($normalized === '') {
                continue;
            }
            $key = strtolower($normalized);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $normalized;
        }

        return $out;
    }

    private function normalizeLocale(string $locale): string
    {
        return trim(str_replace('-', '_', $locale));
    }

    /**
     * @param array<string, mixed> $translations
     * @param array<string, mixed> $data
     * @return array{success: bool, message: string, translations: array<string, mixed>, data: array<string, mixed>}
     */
    private function fail(string $message, array $translations = [], array $data = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'translations' => $translations,
            'data' => $data,
        ];
    }
}
