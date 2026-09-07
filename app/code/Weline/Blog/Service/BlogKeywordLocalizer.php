<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

/**
 * 将文章关键词按当前 locale 展示：优先 LocalModel 译文，否则脚本过滤 + __() 回退。
 */
final class BlogKeywordLocalizer
{
    /**
     * @param callable(string):string|null $translator
     */
    public function localizeKeywordsString(
        string $keywordsRaw,
        string $locale,
        ?string $localKeywords = null,
        ?callable $translator = null,
    ): ?string {
        $fromLocal = trim((string)$localKeywords);
        if ($fromLocal !== '') {
            $tags = $this->splitTags($fromLocal);

            return $tags === [] ? null : implode(',', $tags);
        }

        $tags = $this->localizeTags($keywordsRaw, $locale, $translator);

        return $tags === [] ? null : implode(',', $tags);
    }

    /**
     * @param callable(string):string|null $translator
     * @return list<string>
     */
    public function localizeTags(string $keywordsRaw, string $locale, ?callable $translator = null): array
    {
        $translate = $translator ?? static function (string $text): string {
            return function_exists('__') ? (string)__($text) : $text;
        };

        $preferHan = $this->prefersHanScript($locale);
        $candidates = [];
        foreach ($this->splitTags($keywordsRaw) as $tag) {
            $translated = trim($translate($tag));
            if ($translated === '') {
                continue;
            }
            $candidates[] = [
                'text' => $translated,
                'han' => $this->hasHanScript($translated),
            ];
        }

        if ($candidates === []) {
            return [];
        }

        $matched = [];
        foreach ($candidates as $row) {
            if ($preferHan === $row['han']) {
                $matched[] = $row['text'];
            }
        }

        $pool = $matched !== [] ? $matched : array_column($candidates, 'text');

        return $this->uniquePreserveOrder($pool);
    }

    /**
     * @return list<string>
     */
    public function splitTags(string $keywordsRaw): array
    {
        $keywordsRaw = trim($keywordsRaw);
        if ($keywordsRaw === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/[,，;；|]/u', $keywordsRaw) ?: [] as $tag) {
            $tag = trim((string)$tag);
            if ($tag !== '') {
                $out[] = $tag;
            }
        }

        return $out;
    }

    public function prefersHanScript(string $locale): bool
    {
        $locale = strtolower(str_replace('-', '_', trim($locale)));

        return $locale === 'zh'
            || str_starts_with($locale, 'zh_')
            || $locale === 'ja'
            || str_starts_with($locale, 'ja_')
            || $locale === 'ko'
            || str_starts_with($locale, 'ko_');
    }

    public function hasHanScript(string $text): bool
    {
        return (bool)preg_match('/\p{Han}/u', $text);
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private function uniquePreserveOrder(array $tags): array
    {
        $seen = [];
        $out = [];
        foreach ($tags as $tag) {
            $key = function_exists('mb_strtolower')
                ? mb_strtolower($tag, 'UTF-8')
                : strtolower($tag);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $tag;
        }

        return $out;
    }
}
