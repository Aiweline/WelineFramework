<?php

declare(strict_types=1);

namespace Weline\Cms\Service;

class PageLocaleService
{
    public const ENGLISH_SOURCE_LOCALE = 'en_US';
    public const FALLBACK_SOURCE_LOCALE = 'zh_Hans_CN';

    private ?\Closure $queryExecutor;

    public function __construct(
        private ?\Weline\Cms\Model\PageLocale $pageLocaleModel = null,
        ?callable $queryExecutor = null,
        private ?\Weline\Framework\Database\Transaction\TransactionCoordinatorInterface $transactions = null,
    ) {
        $this->queryExecutor = $queryExecutor === null ? null : \Closure::fromCallable($queryExecutor);
    }

    public function normalizeLocaleCode(string $locale): string
    {
        $locale = trim(str_replace('-', '_', $locale));
        $parts = $locale === '' ? [] : explode('_', $locale);
        if ($parts === [] || count($parts) > 3 || preg_match('/^[A-Za-z]{2,3}$/', $parts[0]) !== 1) {
            throw new \InvalidArgumentException((string)__('语言代码无效：%{1}', $locale));
        }

        $language = strtolower($parts[0]);
        if (count($parts) === 1) {
            return $language;
        }

        $second = $parts[1];
        if (preg_match('/^[A-Za-z]{4}$/', $second) === 1) {
            $normalized = [$language, ucfirst(strtolower($second))];
            if (isset($parts[2])) {
                if (preg_match('/^(?:[A-Za-z]{2}|[0-9]{3})$/', $parts[2]) !== 1) {
                    throw new \InvalidArgumentException((string)__('语言代码无效：%{1}', $locale));
                }
                $normalized[] = strtoupper($parts[2]);
            }
            return implode('_', $normalized);
        }

        if (count($parts) !== 2 || preg_match('/^(?:[A-Za-z]{2}|[0-9]{3})$/', $second) !== 1) {
            throw new \InvalidArgumentException((string)__('语言代码无效：%{1}', $locale));
        }

        return $language . '_' . strtoupper($second);
    }

    /**
     * @param list<string> $supportedLocales
     */
    public function determineSourceLocale(array $supportedLocales, string $websiteDefaultLocale = ''): string
    {
        $normalized = [];
        foreach ($supportedLocales as $locale) {
            try {
                $code = $this->normalizeLocaleCode((string)$locale);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $normalized[$code] = true;
        }
        $supported = array_keys($normalized);

        if (in_array(self::ENGLISH_SOURCE_LOCALE, $supported, true)) {
            return self::ENGLISH_SOURCE_LOCALE;
        }

        try {
            $defaultLocale = $this->normalizeLocaleCode($websiteDefaultLocale);
        } catch (\InvalidArgumentException) {
            $defaultLocale = '';
        }
        if ($defaultLocale !== '' && in_array($defaultLocale, $supported, true)) {
            return $defaultLocale;
        }

        return $supported[0] ?? ($defaultLocale !== '' ? $defaultLocale : self::FALLBACK_SOURCE_LOCALE);
    }

    public function resolveTitleValue(string $localizedTitle, string $sourceTitle, string $legacyTitle): string
    {
        foreach ([$localizedTitle, $sourceTitle, $legacyTitle] as $title) {
            $title = trim($title);
            if ($title !== '') {
                return $title;
            }
        }

        return '';
    }

    /** @return list<string> */
    public function getWebsiteLocales(int $websiteId): array
    {
        $result = $this->query('websites', 'getWebsiteLanguageCodes', ['website_id' => $websiteId]);
        if (is_array($result) && isset($result['data']) && is_array($result['data'])) {
            $result = $result['data'];
        }
        if (is_array($result) && isset($result['language_codes']) && is_array($result['language_codes'])) {
            $result = $result['language_codes'];
        }

        $locales = $this->normalizeSupportedLocales(is_array($result) ? $result : []);
        if ($locales === []) {
            throw new \RuntimeException((string)__('网站 %{1} 没有配置可用语言。', [$websiteId]));
        }

        return $locales;
    }

    public function assertWebsiteLocale(int $websiteId, string $locale): string
    {
        return $this->assertSupportedLocale($this->getWebsiteLocales($websiteId), $locale);
    }

    public function resolveSourceLocaleForWebsite(int $websiteId): string
    {
        $supported = $this->getWebsiteLocales($websiteId);
        return $this->determineSourceLocale($supported, $this->getWebsiteDefaultLocale($websiteId));
    }

    public function resolveSourceLocale(\Weline\Cms\Model\Page $page): string
    {
        $supported = $this->getWebsiteLocales($page->getWebsiteId());
        $current = trim($page->getSourceLocale());
        if ($current !== '') {
            try {
                return $this->assertSupportedLocale($supported, $current);
            } catch (\InvalidArgumentException) {
                // Legacy rows may reference a locale removed from the website.
            }
        }

        return $this->determineSourceLocale($supported, $this->getWebsiteDefaultLocale($page->getWebsiteId()));
    }

    /** @return array<string, \Weline\Cms\Model\PageLocale> */
    public function loadTitles(\Weline\Cms\Model\Page $page): array
    {
        if ($page->getPageId() <= 0) {
            return [];
        }

        $items = (clone $this->localeModel())->clearData()->reset()
            ->where(\Weline\Cms\Model\PageLocale::schema_fields_PAGE_ID, $page->getPageId())
            ->select()
            ->fetch()
            ->getItems();
        $titles = [];
        foreach ($items as $item) {
            if ($item instanceof \Weline\Cms\Model\PageLocale) {
                $titles[$item->getLocaleCode()] = $item;
            }
        }

        return $titles;
    }

    public function resolveTitle(\Weline\Cms\Model\Page $page, string $locale): string
    {
        $locale = $this->assertWebsiteLocale($page->getWebsiteId(), $locale);
        $sourceLocale = $this->resolveSourceLocale($page);
        $titles = $this->loadTitles($page);

        return $this->resolveTitleValue(
            $titles[$locale]?->getTitle() ?? '',
            $titles[$sourceLocale]?->getTitle() ?? '',
            $page->getTitle(),
        );
    }

    /**
     * @param list<string> $supportedLocales
     */
    public function resolveEditorLocale(array $supportedLocales, string $requestedLocale, string $sourceLocale): string
    {
        $supported = $this->normalizeSupportedLocales($supportedLocales);
        if ($supported === []) {
            throw new \RuntimeException((string)__('当前网站没有可用于编辑 CMS 页面的语言。'));
        }

        if (trim($requestedLocale) !== '') {
            try {
                $requested = $this->normalizeLocaleCode($requestedLocale);
                if (in_array($requested, $supported, true)) {
                    return $requested;
                }
            } catch (\InvalidArgumentException) {
            }
        }
        if (in_array('en_US', $supported, true)) {
            return 'en_US';
        }
        try {
            $source = $this->normalizeLocaleCode($sourceLocale);
            if (in_array($source, $supported, true)) {
                return $source;
            }
        } catch (\InvalidArgumentException) {
        }

        return $supported[0];
    }

    /**
     * @param list<string> $supportedLocales
     * @return array<string,string>
     */
    public function normalizeSubmittedTitles(mixed $titles, array $supportedLocales): array
    {
        if (is_string($titles)) {
            if (trim($titles) === '') {
                return [];
            }
            try {
                $titles = json_decode($titles, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException((string)__('CMS 多语言标题数据无效。'), 0, $exception);
            }
        }
        if ($titles === null || $titles === []) {
            return [];
        }
        if (!is_array($titles)) {
            throw new \InvalidArgumentException((string)__('CMS 多语言标题数据无效。'));
        }

        $supported = $this->normalizeSupportedLocales($supportedLocales);
        $normalized = [];
        foreach ($titles as $locale => $title) {
            if (!is_scalar($title) && $title !== null) {
                throw new \InvalidArgumentException((string)__('CMS 页面标题必须是文本。'));
            }
            $locale = $this->assertSupportedLocale($supported, (string)$locale);
            $title = trim((string)$title);
            if ($title !== '') {
                $normalized[$locale] = $title;
            }
        }

        return $normalized;
    }

    /**
     * @return array{
     *   supported_locales:list<string>,source_locale:string,current_locale:string,
     *   current_title:string,titles:array<string,string>,entries:array<string,array{title:string,origin:string,source_hash:string}>
     * }
     */
    public function buildEditorPayload(\Weline\Cms\Model\Page $page, string $requestedLocale = ''): array
    {
        $supported = $this->getWebsiteLocales($page->getWebsiteId());
        $sourceLocale = $this->resolveSourceLocale($page);
        $currentLocale = $this->resolveEditorLocale($supported, $requestedLocale, $sourceLocale);
        $stored = $this->loadTitles($page);
        $titles = [];
        $entries = [];
        foreach ($supported as $locale) {
            $row = $stored[$locale] ?? null;
            $title = $row?->getTitle() ?? '';
            $origin = $row?->getOrigin() ?? '';
            $sourceHash = $row?->getSourceHash() ?? '';
            if ($locale === $sourceLocale && trim($title) === '') {
                $title = $page->getTitle();
                $origin = \Weline\Cms\Model\PageLocale::ORIGIN_SOURCE;
                $sourceHash = hash('sha256', $title);
            }
            $titles[$locale] = $title;
            $entries[$locale] = [
                'title' => $title,
                'origin' => $origin,
                'source_hash' => $sourceHash,
            ];
        }

        return [
            'supported_locales' => $supported,
            'source_locale' => $sourceLocale,
            'current_locale' => $currentLocale,
            'current_title' => $titles[$currentLocale] ?? '',
            'titles' => $titles,
            'entries' => $entries,
        ];
    }

    /**
     * @return array{locale_code:string,source_locale:string,supported_locales:list<string>}
     */
    public function prepareWriteLocales(
        \Weline\Cms\Model\Page $page,
        string $requestedLocale = '',
        string $sourceLocale = '',
    ): array {
        $supported = $this->getWebsiteLocales($page->getWebsiteId());
        $sourceLocale = trim($sourceLocale) !== ''
            ? $this->assertSupportedLocale($supported, $sourceLocale)
            : $this->sourceLocaleFromSupported($page, $supported);
        $requestedLocale = trim($requestedLocale) !== ''
            ? $this->assertSupportedLocale($supported, $requestedLocale)
            : $sourceLocale;

        return [
            'locale_code' => $requestedLocale,
            'source_locale' => $sourceLocale,
            'supported_locales' => $supported,
        ];
    }

    public function upsertTitle(
        \Weline\Cms\Model\Page $page,
        string $locale,
        string $title,
        string $origin = \Weline\Cms\Model\PageLocale::ORIGIN_MANUAL,
        string $sourceHash = '',
        ?array $supportedLocales = null,
    ): \Weline\Cms\Model\PageLocale {
        if ($page->getPageId() <= 0) {
            throw new \InvalidArgumentException((string)__('CMS 页面必须先保存，才能写入多语言标题。'));
        }
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException((string)__('CMS 页面标题不能为空。'));
        }
        if (!in_array($origin, \Weline\Cms\Model\PageLocale::ORIGINS, true)) {
            throw new \InvalidArgumentException((string)__('CMS 页面标题来源无效：%{1}', [$origin]));
        }
        $sourceHash = trim($sourceHash);
        if (strlen($sourceHash) > 64) {
            throw new \InvalidArgumentException((string)__('CMS 页面标题源哈希长度不能超过 64。'));
        }

        $supported = $supportedLocales === null
            ? $this->getWebsiteLocales($page->getWebsiteId())
            : $this->normalizeSupportedLocales($supportedLocales);
        if ($supported === []) {
            throw new \RuntimeException((string)__('当前网站没有可用于保存 CMS 标题的语言。'));
        }
        $locale = $this->assertSupportedLocale($supported, $locale);
        $sourceLocale = $this->sourceLocaleFromSupported($page, $supported);
        $connection = $page->getConnection();
        $save = fn(): \Weline\Cms\Model\PageLocale => $this->persistTitle(
            $page,
            $locale,
            $title,
            $origin,
            $sourceHash,
            $sourceLocale,
        );
        $transactions = $this->transactionCoordinator();

        return $transactions->isActive($connection) ? $save() : $transactions->run($connection, $save);
    }

    /**
     * Persist an AI title only while the target locale is still empty.
     *
     * @param list<string>|null $supportedLocales
     */
    public function fillMissingTitle(
        \Weline\Cms\Model\Page $page,
        string $locale,
        string $title,
        string $sourceHash,
        ?array $supportedLocales = null,
    ): ?\Weline\Cms\Model\PageLocale {
        if ($page->getPageId() <= 0) {
            throw new \InvalidArgumentException((string)__('CMS 页面必须先保存，才能写入多语言标题。'));
        }
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException((string)__('机器翻译标题不能为空。'));
        }
        $sourceHash = trim($sourceHash);
        if ($sourceHash === '' || strlen($sourceHash) > 64) {
            throw new \InvalidArgumentException((string)__('机器翻译标题源哈希无效。'));
        }

        $supported = $supportedLocales === null
            ? $this->getWebsiteLocales($page->getWebsiteId())
            : $this->normalizeSupportedLocales($supportedLocales);
        if ($supported === []) {
            throw new \RuntimeException((string)__('当前网站没有可用于保存 CMS 标题的语言。'));
        }
        $locale = $this->assertSupportedLocale($supported, $locale);
        $sourceLocale = $this->sourceLocaleFromSupported($page, $supported);
        if ($locale === $sourceLocale) {
            throw new \InvalidArgumentException((string)__('源语言标题不能由机器翻译覆盖。'));
        }

        $connection = $page->getConnection();
        $save = function () use ($page, $locale, $title, $sourceHash, $sourceLocale): ?\Weline\Cms\Model\PageLocale {
            $existing = $this->findTitle($page->getPageId(), $locale, true);
            if ($existing !== null && trim($existing->getTitle()) !== '') {
                return null;
            }

            return $this->persistTitle(
                $page,
                $locale,
                $title,
                \Weline\Cms\Model\PageLocale::ORIGIN_AI,
                $sourceHash,
                $sourceLocale,
            );
        };
        $transactions = $this->transactionCoordinator();
        if ($transactions->isActive($connection)) {
            return $save();
        }

        try {
            return $transactions->run($connection, $save);
        } catch (\Throwable $throwable) {
            // A concurrent manual insert may win the unique (page, locale)
            // key after our missing-row check. Treat that as the desired
            // outcome only when the durable row is now non-empty.
            $latest = $this->findTitle($page->getPageId(), $locale);
            if ($latest !== null && trim($latest->getTitle()) !== '') {
                return null;
            }
            throw $throwable;
        }
    }

    public function backfillLegacyTitle(\Weline\Cms\Model\Page $page): ?\Weline\Cms\Model\PageLocale
    {
        if ($page->getPageId() <= 0 || trim($page->getTitle()) === '') {
            return null;
        }
        $sourceLocale = $this->resolveSourceLocale($page);
        $existing = $this->findTitle($page->getPageId(), $sourceLocale);
        if ($existing !== null && trim($existing->getTitle()) !== '') {
            return $existing;
        }

        return $this->upsertTitle(
            $page,
            $sourceLocale,
            $page->getTitle(),
            \Weline\Cms\Model\PageLocale::ORIGIN_SOURCE,
            hash('sha256', $page->getTitle()),
        );
    }

    private function persistTitle(
        \Weline\Cms\Model\Page $page,
        string $locale,
        string $title,
        string $origin,
        string $sourceHash,
        string $sourceLocale,
    ): \Weline\Cms\Model\PageLocale {
        $model = $this->findTitle($page->getPageId(), $locale) ?? clone $this->localeModel();
        $model->setData(\Weline\Cms\Model\PageLocale::schema_fields_PAGE_ID, $page->getPageId());
        $model->setData(\Weline\Cms\Model\PageLocale::schema_fields_LOCALE_CODE, $locale);
        $model->setData(\Weline\Cms\Model\PageLocale::schema_fields_TITLE, $title);
        $model->setData(\Weline\Cms\Model\PageLocale::schema_fields_ORIGIN, $origin);
        $model->setData(\Weline\Cms\Model\PageLocale::schema_fields_SOURCE_HASH, $sourceHash);
        $model->save();

        if ($locale === $sourceLocale
            && ($page->getTitle() !== $title || $page->getSourceLocale() !== $sourceLocale)
        ) {
            $page->setData(\Weline\Cms\Model\Page::schema_fields_TITLE, $title);
            $page->setSourceLocale($sourceLocale);
            $page->save();
        }

        return $model;
    }

    private function findTitle(
        int $pageId,
        string $locale,
        bool $forUpdate = false,
    ): ?\Weline\Cms\Model\PageLocale {
        $model = clone $this->localeModel();
        $model->clearData()->reset()
            ->where(\Weline\Cms\Model\PageLocale::schema_fields_PAGE_ID, $pageId)
            ->where(\Weline\Cms\Model\PageLocale::schema_fields_LOCALE_CODE, $locale);
        if ($forUpdate) {
            $model->additional('FOR UPDATE');
        }
        $model->find()->fetch();

        return $model->getPageLocaleId() > 0 ? $model : null;
    }

    /** @param list<string> $supported */
    private function sourceLocaleFromSupported(\Weline\Cms\Model\Page $page, array $supported): string
    {
        $current = trim($page->getSourceLocale());
        if ($current !== '') {
            try {
                return $this->assertSupportedLocale($supported, $current);
            } catch (\InvalidArgumentException) {
                // Re-select below when the stored source locale is no longer supported.
            }
        }

        return $this->determineSourceLocale($supported, $this->getWebsiteDefaultLocale($page->getWebsiteId()));
    }

    /** @param list<string> $supported */
    private function assertSupportedLocale(array $supported, string $locale): string
    {
        $locale = $this->normalizeLocaleCode($locale);
        if (!in_array($locale, $supported, true)) {
            throw new \InvalidArgumentException((string)__('语言 %{1} 不属于当前网站。', [$locale]));
        }

        return $locale;
    }

    /** @return list<string> */
    private function normalizeSupportedLocales(array $locales): array
    {
        $normalized = [];
        foreach ($locales as $locale) {
            try {
                $code = $this->normalizeLocaleCode((string)$locale);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $normalized[$code] = true;
        }

        return array_keys($normalized);
    }

    private function getWebsiteDefaultLocale(int $websiteId): string
    {
        $website = $this->query('websites', 'getWebsiteById', ['website_id' => $websiteId]);
        if (is_array($website) && isset($website['data']) && is_array($website['data'])) {
            $website = $website['data'];
        }
        if (!is_array($website)) {
            return '';
        }

        foreach (['default_language', 'default_language_code', 'default_locale', 'language_code'] as $key) {
            $locale = trim((string)($website[$key] ?? ''));
            if ($locale !== '') {
                return $locale;
            }
        }

        return '';
    }

    private function query(string $provider, string $operation, array $params): mixed
    {
        if ($this->queryExecutor !== null) {
            return ($this->queryExecutor)($provider, $operation, $params);
        }
        if (!function_exists('w_query')) {
            throw new \RuntimeException((string)__('统一查询服务不可用，无法读取网站语言。'));
        }

        return \w_query($provider, $operation, $params);
    }

    private function localeModel(): \Weline\Cms\Model\PageLocale
    {
        return $this->pageLocaleModel ??= \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Cms\Model\PageLocale::class,
        );
    }

    private function transactionCoordinator(): \Weline\Framework\Database\Transaction\TransactionCoordinatorInterface
    {
        return $this->transactions ??= \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Framework\Database\Transaction\TransactionCoordinatorInterface::class,
        );
    }
}
