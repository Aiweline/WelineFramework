<?php

declare(strict_types=1);

namespace Weline\Cms\Api\Data;

/** Immutable identity of one CMS Store/Locale variant. */
final readonly class CmsPageVariantIdentity
{
    public string $localeCode;

    public function __construct(
        public int $pageId,
        public int $websiteId,
        public int $storeId,
        string $localeCode,
    ) {
        if ($pageId <= 0 || $websiteId < 0 || $storeId <= 0) {
            throw new \InvalidArgumentException((string)__('CMS 页面变体身份无效。'));
        }
        $localeCode = self::normalizeLocale($localeCode);
        if ($localeCode === '') {
            throw new \InvalidArgumentException((string)__('CMS 页面变体必须指定语言。'));
        }
        $this->localeCode = $localeCode;
    }

    /** @return array{page_id:int,website_id:int,store_id:int,locale_code:string} */
    public function toArray(): array
    {
        return [
            'page_id' => $this->pageId,
            'website_id' => $this->websiteId,
            'store_id' => $this->storeId,
            'locale_code' => $this->localeCode,
        ];
    }

    private static function normalizeLocale(string $locale): string
    {
        $locale = trim(str_replace('-', '_', $locale));
        if ($locale === '' || preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z]{4})?(?:_(?:[A-Za-z]{2}|[0-9]{3}))?$/D', $locale) !== 1) {
            return '';
        }
        $parts = explode('_', $locale);
        $parts[0] = strtolower($parts[0]);
        if (isset($parts[1])) {
            $parts[1] = strlen($parts[1]) === 4 ? ucfirst(strtolower($parts[1])) : strtoupper($parts[1]);
        }
        if (isset($parts[2])) {
            $parts[2] = strtoupper($parts[2]);
        }
        return implode('_', $parts);
    }
}
