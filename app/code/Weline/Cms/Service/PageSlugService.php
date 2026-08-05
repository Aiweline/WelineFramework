<?php

declare(strict_types=1);

namespace Weline\Cms\Service;

use Weline\Cms\Model\Page;

final class PageSlugService
{
    private const MAX_SLUG_LENGTH = 160;
    private const RESERVED_TOP_LEVEL = [
        'admin',
        'backend',
        'api',
        'rest',
        'graphql',
        'static',
        'pub',
        'media',
        'uploads',
        'cms',
        'theme',
        'customer',
        'account',
        'cart',
        'checkout',
        'product',
        'category',
        'catalog',
        'search',
        'wishlist',
    ];

    private ?\Closure $conflictChecker;
    private \Transliterator|false|null $transliterator = null;
    private bool $transliteratorResolved = false;

    public function __construct(
        private ?Page $pageModel = null,
        ?callable $conflictChecker = null,
    ) {
        $this->conflictChecker = $conflictChecker === null ? null : \Closure::fromCallable($conflictChecker);
    }

    public function slugify(string $title): string
    {
        $value = trim($title);
        $transliterator = $this->transliterator();
        if ($transliterator instanceof \Transliterator) {
            $transliterated = $transliterator->transliterate($value);
            if (is_string($transliterated)) {
                $value = $transliterated;
            }
        }
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        $value = trim($value, '-');
        if ($value === '') {
            return 'page';
        }

        return rtrim(substr($value, 0, self::MAX_SLUG_LENGTH), '-');
    }

    /**
     * @return array{slug:string,identifier:string,mode:string,source_hash:string}
     */
    public function resolveForSave(
        Page $page,
        string $sourceTitle,
        string $submittedSlug,
        string $pathGroup,
        int $websiteId,
        string $status,
        string $requestedMode = '',
    ): array {
        $currentSlug = $this->normalizeSubmittedSlug($page->getSlug());
        $submittedSlug = $this->normalizeSubmittedSlug($submittedSlug);
        $pathGroup = trim($pathGroup, '/ ');
        $requestedMode = in_array($requestedMode, Page::SLUG_MODES, true) ? $requestedMode : '';
        $previousMode = $page->getSlugMode();
        $sourceHash = hash('sha256', trim($sourceTitle));
        $existingPage = $page->getPageId() > 0;
        $published = $status === Page::STATUS_PUBLISHED
            || $page->getStatus() === Page::STATUS_PUBLISHED
            || $previousMode === Page::SLUG_MODE_FROZEN;

        if ($published && $existingPage && $currentSlug !== '') {
            return $this->decision($currentSlug, $pathGroup, Page::SLUG_MODE_FROZEN, $sourceHash);
        }

        $mode = $this->resolveMode(
            $existingPage,
            $previousMode,
            $requestedMode,
            $currentSlug,
            $submittedSlug,
        );
        if ($published) {
            $mode = Page::SLUG_MODE_FROZEN;
        }

        if ($mode === Page::SLUG_MODE_AUTO) {
            $base = $this->slugify($sourceTitle);
            if ($pathGroup === '' && in_array($base, self::RESERVED_TOP_LEVEL, true)) {
                $base = 'page-' . $base;
            }
            $slug = $this->uniqueAutoSlug($base, $pathGroup, $websiteId, $page->getPageId());
        } else {
            $slug = $submittedSlug !== '' ? $submittedSlug : $currentSlug;
            if ($slug === '') {
                $slug = $this->slugify($sourceTitle);
            }
        }

        return $this->decision($slug, $pathGroup, $mode, $sourceHash);
    }

    public function isHistoricalRandomSlug(string $slug): bool
    {
        return preg_match('/^\d{14}-[a-f0-9]{8}$/i', trim($slug)) === 1;
    }

    private function resolveMode(
        bool $existingPage,
        string $previousMode,
        string $requestedMode,
        string $currentSlug,
        string $submittedSlug,
    ): string {
        if ($requestedMode === Page::SLUG_MODE_MANUAL) {
            return Page::SLUG_MODE_MANUAL;
        }
        if ($requestedMode === Page::SLUG_MODE_AUTO) {
            return Page::SLUG_MODE_AUTO;
        }
        if ($previousMode === Page::SLUG_MODE_MANUAL) {
            return Page::SLUG_MODE_MANUAL;
        }
        if ($previousMode === Page::SLUG_MODE_FROZEN) {
            return Page::SLUG_MODE_FROZEN;
        }
        if ($previousMode === Page::SLUG_MODE_AUTO) {
            return $submittedSlug !== '' && $currentSlug !== '' && $submittedSlug !== $currentSlug
                ? Page::SLUG_MODE_MANUAL
                : Page::SLUG_MODE_AUTO;
        }
        if ($existingPage) {
            return $this->isHistoricalRandomSlug($currentSlug)
                ? Page::SLUG_MODE_AUTO
                : Page::SLUG_MODE_MANUAL;
        }

        return $submittedSlug === '' ? Page::SLUG_MODE_AUTO : Page::SLUG_MODE_MANUAL;
    }

    private function uniqueAutoSlug(string $base, string $pathGroup, int $websiteId, int $pageId): string
    {
        if (!$this->identifierExists($this->composeIdentifier($pathGroup, $base), $websiteId, $pageId)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 1000; $suffix++) {
            $suffixText = '-' . $suffix;
            $candidate = rtrim(substr($base, 0, self::MAX_SLUG_LENGTH - strlen($suffixText)), '-') . $suffixText;
            if (!$this->identifierExists($this->composeIdentifier($pathGroup, $candidate), $websiteId, $pageId)) {
                return $candidate;
            }
        }

        $suffixText = '-' . substr(hash('sha256', $websiteId . "\0" . $pathGroup . "\0" . $base), 0, 10);
        return rtrim(substr($base, 0, self::MAX_SLUG_LENGTH - strlen($suffixText)), '-') . $suffixText;
    }

    private function identifierExists(string $identifier, int $websiteId, int $pageId): bool
    {
        if ($this->conflictChecker !== null) {
            return (bool)($this->conflictChecker)($identifier, $websiteId, $pageId);
        }
        if ($this->pageModel === null) {
            return false;
        }

        $model = clone $this->pageModel;
        $query = $model->clearData()->reset()
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Page::schema_fields_IDENTIFIER, $identifier);
        if ($pageId > 0) {
            $query->where(Page::schema_fields_ID, $pageId, '!=');
        }

        return !empty($query->find()->fetchArray());
    }

    /** @return array{slug:string,identifier:string,mode:string,source_hash:string} */
    private function decision(string $slug, string $pathGroup, string $mode, string $sourceHash): array
    {
        return [
            'slug' => $slug,
            'identifier' => $this->composeIdentifier($pathGroup, $slug),
            'mode' => $mode,
            'source_hash' => $sourceHash,
        ];
    }

    private function composeIdentifier(string $pathGroup, string $slug): string
    {
        return $pathGroup === '' ? $slug : $pathGroup . '/' . $slug;
    }

    private function normalizeSubmittedSlug(string $slug): string
    {
        $slug = trim(rawurldecode($slug));
        if ($slug === '') {
            return '';
        }

        return $this->slugify($slug);
    }

    private function transliterator(): \Transliterator|false|null
    {
        if (!$this->transliteratorResolved) {
            $this->transliteratorResolved = true;
            $this->transliterator = class_exists(\Transliterator::class)
                ? \Transliterator::create('Any-Latin; Latin-ASCII; Lower()')
                : null;
        }

        return $this->transliterator;
    }
}
