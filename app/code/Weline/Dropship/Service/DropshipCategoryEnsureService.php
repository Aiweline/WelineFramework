<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Service\ProductCategoryAdminService;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Service\ProductCategoryEavBootstrap;

/**
 * Shell-owned category ensure: only real taxonomy segments + source_platform tags.
 *
 * Forbidden: blunt top-level folders like 「货源商城」「CJ货源」(or renamed equivalents).
 * Ops identify dropship rows via category EAV `source_platform`, not via branded parents.
 * Legacy `/sourcing/{provider}` org nodes are lifted once, then inactivated.
 */
class DropshipCategoryEnsureService
{
    /** @deprecated Technical path prefix only; no longer created as a category name. */
    public const ROOT_CODE = 'sourcing';

    /**
     * @return list<int> leaf category ids to assign
     */
    public function ensureFromRemotePath(
        int $websiteId,
        string $providerCode,
        string $remotePath,
        string $locale = 'zh_Hans_CN',
    ): array {
        $providerCode = strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', trim($providerCode)) ?? 'provider');
        $providerCode = trim($providerCode, '-') ?: 'provider';
        $locale = trim($locale) !== '' ? $locale : 'zh_Hans_CN';

        ObjectManager::getInstance(ProductCategoryEavBootstrap::class)->ensureCategorySchema();

        $admin = ObjectManager::getInstance(ProductCategoryAdminService::class);
        $attrs = ObjectManager::getInstance(ProductCategoryAttributeService::class);

        $this->retireLegacyShellBranches($admin, $attrs, $websiteId, $providerCode, $locale);

        $parentId = 0;
        foreach ($this->parseSegments($remotePath, $providerCode) as $segment) {
            $parentId = $this->ensureChild(
                $admin,
                $attrs,
                $websiteId,
                $parentId,
                $segment['name'],
                $segment['code'],
                $locale,
                '',
                $providerCode,
            );
        }

        return $parentId > 0 ? [$parentId] : [];
    }

    /**
     * Split remote category labels into leaf name/code pairs only.
     * Strips obsolete shell prefixes (`sourcing` / provider code) if present.
     *
     * @return list<array{name:string,code:string}>
     */
    public function parseSegments(string $remotePath, string $providerCode = ''): array
    {
        $providerCode = strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', trim($providerCode)) ?? '');
        $providerCode = trim($providerCode, '-');
        $remotePath = trim(str_replace(['>', '／', '、', '\\'], ['/', '/', '/', '/'], $remotePath));
        $parts = preg_split('#[/=|]+#u', $remotePath) ?: [];
        $out = [];
        $skippingPrefix = true;
        foreach ($parts as $part) {
            $name = trim((string)$part);
            if ($name === '') {
                continue;
            }
            $code = $this->slugify($name);
            if ($code === '' || $code === 'category') {
                continue;
            }
            if ($skippingPrefix) {
                if (
                    $code === self::ROOT_CODE
                    || $code === 'dropship'
                    || ($providerCode !== '' && $code === $providerCode)
                ) {
                    continue;
                }
                $skippingPrefix = false;
            }
            if (str_contains($code, '/')) {
                $code = (string)array_slice(explode('/', $code), -1)[0];
            }
            if ($code === '' || $code === 'category') {
                continue;
            }
            $out[] = ['name' => $name, 'code' => $code];
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }

    public function slugify(string $value): string
    {
        $source = trim($value);
        if ($source === '') {
            return '';
        }
        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('Han-Latin; Latin-ASCII; Lower()');
            if ($transliterator !== null) {
                $transliterated = $transliterator->transliterate($source);
                if (is_string($transliterated) && trim($transliterated) !== '') {
                    $source = $transliterated;
                }
            }
        }
        $slug = strtolower($source);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            return 'cat-' . substr(sha1(mb_strtolower(trim($value), 'UTF-8')), 0, 10);
        }
        if (strlen($slug) > 48) {
            $slug = rtrim(substr($slug, 0, 48), '-');
        }

        return $slug !== '' ? $slug : 'category';
    }

    /**
     * Retire blunt legacy shell folders (`/sourcing`, `/sourcing/{provider}`) only.
     * Taxonomy under them stays addressable for old product links; storefront hides shell crumbs,
     * and admin tree hides the whole `/sourcing…` branch. New ensures attach at website root.
     */
    private function retireLegacyShellBranches(
        ProductCategoryAdminService $admin,
        ProductCategoryAttributeService $attrs,
        int $websiteId,
        string $providerCode,
        string $locale,
    ): void {
        /** @var CategoryRepository $repo */
        $repo = ObjectManager::getInstance(CategoryRepository::class);
        $providerCode = $this->slugify($providerCode);
        $shellPrefix = self::ROOT_CODE . '/' . $providerCode;
        $all = $repo->listAll($websiteId);
        $ids = [];
        foreach ($all as $row) {
            $id = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return;
        }
        $codeMap = $attrs->readCodeMap($websiteId, $ids, $locale);
        $nameMap = $attrs->readNameMap($websiteId, $ids, $locale);

        foreach ($all as $row) {
            $id = (int)($row[Category::schema_fields_ID] ?? 0);
            $path = strtolower(trim(str_replace('\\', '/', (string)($row[Category::schema_fields_PATH] ?? '')), '/'));
            $status = strtolower(trim((string)($row[Category::schema_fields_STATUS] ?? 'active')));
            if ($id <= 0 || $status === 'inactive') {
                continue;
            }
            if ($path !== self::ROOT_CODE && $path !== $shellPrefix) {
                continue;
            }
            $code = $this->slugify((string)($codeMap[$id] ?? ($path === self::ROOT_CODE ? self::ROOT_CODE : $providerCode)));
            $name = trim((string)($nameMap[$id] ?? ''));
            $this->inactivateShellNode($admin, $attrs, $websiteId, $id, $code, $locale, $name);
        }
    }

    private function inactivateShellNode(
        ProductCategoryAdminService $admin,
        ProductCategoryAttributeService $attrs,
        int $websiteId,
        int $categoryId,
        string $code,
        string $locale,
        string $fallbackName = '',
    ): void {
        if ($categoryId <= 0) {
            return;
        }
        $row = ObjectManager::getInstance(CategoryRepository::class)->findById($websiteId, $categoryId);
        if ($row === null) {
            return;
        }
        $parentId = max(0, (int)($row->getData(Category::schema_fields_PARENT_ID) ?? 0));
        $names = $attrs->readNameMap($websiteId, [$categoryId], $locale);
        $name = trim((string)($names[$categoryId] ?? ''));
        if ($name === '') {
            $name = $fallbackName !== '' ? $fallbackName : $code;
        }
        try {
            // Keep parent to avoid reshuffling leftover children; only mark inactive.
            $admin->save(
                $websiteId,
                $categoryId,
                $parentId,
                $name,
                'inactive',
                $code,
                $locale,
            );
            $attrs->writeSourcePlatform(
                $websiteId,
                $categoryId,
                $code === self::ROOT_CODE ? 'dropship' : $code,
                $locale,
            );
        } catch (\Throwable $e) {
            w_log_warning('dropship inactivate shell category failed #' . $categoryId . ': ' . $e->getMessage());
        }
    }

    private function ensureChild(
        ProductCategoryAdminService $admin,
        ProductCategoryAttributeService $attrs,
        int $websiteId,
        int $parentId,
        string $name,
        string $code,
        string $locale,
        string $summary,
        string $sourcePlatform,
    ): int {
        $name = trim($name);
        $code = $this->slugify($code !== '' ? $code : $name);
        if ($name === '' || $code === '') {
            return $parentId;
        }

        $existingId = $admin->findSiblingIdByLocalizedName($websiteId, $parentId, $name, $locale);
        if ($existingId <= 0) {
            $existingId = $this->findSiblingIdByCode($websiteId, $parentId, $code, $locale);
        }

        if ($existingId > 0) {
            $attrs->writeName($websiteId, $existingId, $name, $locale);
            $attrs->writeCode($websiteId, $existingId, $code, $locale);
            if ($summary !== '') {
                $attrs->writeSummary($websiteId, $existingId, $summary, $locale);
            }
            $attrs->writeSourcePlatform($websiteId, $existingId, $sourcePlatform, $locale);

            return $existingId;
        }

        $saved = $admin->save(
            $websiteId,
            0,
            $parentId,
            $name,
            'active',
            $code,
            $locale,
            null,
            null,
            null,
            $summary !== '' ? $summary : null,
        );
        $categoryId = (int)($saved['category_id'] ?? 0);
        if ($categoryId > 0) {
            $attrs->writeSourcePlatform($websiteId, $categoryId, $sourcePlatform, $locale);
        }

        return $categoryId;
    }

    private function findSiblingIdByCode(
        int $websiteId,
        int $parentId,
        string $code,
        string $locale,
    ): int {
        $code = $this->slugify($code);
        if ($code === '') {
            return 0;
        }
        /** @var CategoryRepository $repo */
        $repo = ObjectManager::getInstance(CategoryRepository::class);
        $attrs = ObjectManager::getInstance(ProductCategoryAttributeService::class);
        $siblingIds = [];
        foreach ($repo->listSiblings($websiteId, $parentId) as $row) {
            $id = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($id > 0) {
                $siblingIds[] = $id;
            }
        }
        if ($siblingIds === []) {
            return 0;
        }
        foreach ([$locale, ''] as $candidateLocale) {
            $codes = $attrs->readCodeMap($websiteId, $siblingIds, $candidateLocale);
            foreach ($siblingIds as $categoryId) {
                if ($this->slugify((string)($codes[$categoryId] ?? '')) === $code) {
                    return $categoryId;
                }
            }
            if ($candidateLocale === '') {
                break;
            }
        }

        return 0;
    }
}
