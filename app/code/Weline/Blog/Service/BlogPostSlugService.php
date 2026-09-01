<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\I18nAiTranslationAdapter;

final class BlogPostSlugService
{
    public function __construct(
        private readonly I18nAiTranslationAdapter $aiAdapter,
        private readonly AiTranslationConfig $aiConfig,
    ) {
    }

    /**
     * @return array{slug:string,mode:string,errors:list<string>}
     */
    public function suggestSlug(string $title, string $locale, bool $useAi = false): array
    {
        $title = trim($title);
        if ($title === '') {
            return ['slug' => '', 'mode' => $useAi ? 'ai' : 'auto', 'errors' => []];
        }

        if (!$useAi) {
            return [
                'slug' => $this->slugifyFromTitle($title),
                'mode' => 'auto',
                'errors' => [],
            ];
        }

        $slugLocale = trim($locale) !== '' ? trim($locale) : $this->aiConfig->getSourceLocale();

        if ($this->shouldSlugifyInLocaleDirectly($title, $slugLocale)) {
            return [
                'slug' => $this->slugifyFromTitle($title),
                'mode' => 'ai',
                'errors' => [],
            ];
        }

        $sourceLocale = $this->isMostlyLatin($title)
            ? $slugLocale
            : $this->aiConfig->getSourceLocale();
        $translation = $this->aiAdapter->translateBatch([$title], $sourceLocale, $slugLocale);
        $errors = array_values(array_map('strval', (array)($translation['errors'] ?? [])));
        $translated = trim((string)($translation['translations'][$title] ?? ''));

        if ($translated === '') {
            return [
                'slug' => $this->slugifyFromTitle($title),
                'mode' => 'ai_fallback',
                'errors' => $errors,
            ];
        }

        return [
            'slug' => $this->slugifyFromTitle($translated),
            'mode' => 'ai',
            'errors' => $errors,
        ];
    }

    private function shouldSlugifyInLocaleDirectly(string $title, string $slugLocale): bool
    {
        if (!$this->isLatinSlugLocale($slugLocale)) {
            return true;
        }

        return $this->isMostlyLatin($title);
    }

    private function isLatinSlugLocale(string $locale): bool
    {
        $locale = strtolower(trim($locale));
        if ($locale === '') {
            return false;
        }

        return str_starts_with($locale, 'en')
            || str_starts_with($locale, 'de')
            || str_starts_with($locale, 'fr')
            || str_starts_with($locale, 'es')
            || str_starts_with($locale, 'it')
            || str_starts_with($locale, 'pt')
            || str_starts_with($locale, 'nl');
    }

    private function isMostlyLatin(string $title): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $title) ?? '';
        if ($letters === '') {
            return true;
        }

        $latin = preg_replace('/[^a-zA-Z]/u', '', $letters) ?? '';

        return mb_strlen($latin, 'UTF-8') >= (mb_strlen($letters, 'UTF-8') * 0.6);
    }

    public function slugifyFromTitle(string $title): string
    {
        if (class_exists(\Weline\Cms\Service\PageSlugService::class)) {
            return (new \Weline\Cms\Service\PageSlugService())->slugify($title);
        }

        $value = trim($title);
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        $value = trim($value, '-');
        if ($value === '') {
            return 'page';
        }

        return rtrim(substr($value, 0, 160), '-');
    }
}
