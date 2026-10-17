<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Helper\StorefrontImagePlaceholder;
use Weline\Theme\Helper\WidgetI18n;

/**
 * Assembles homepage image-gallery variant=looks wall data outside the widget template.
 *
 * Order: live Review gallery → defer website-safe config fill → placeholders.
 * Config catalog checks are batched and only run when live rows are short.
 */
final class ImageGalleryLooksAssembler
{
    /**
     * Theme-editor canvas prefers typed editor_context scope identity, then RequestContext.
     */
    public function resolveWebsiteId(?Request $request = null): int
    {
        try {
            $request ??= ObjectManager::getInstance(Request::class);
            $editorMode = \strtolower(\trim((string)$request->getParam('editor_mode', '')));
            $shell = \strtolower(\trim((string)$request->getParam('shell', '')));
            $isEditorCanvas = $editorMode === '1' || $editorMode === 'true'
                || $shell === 'theme-editor';
            if ($isEditorCanvas) {
                $raw = $request->getParam('editor_context', null);
                $decoded = null;
                if (\is_string($raw) && $raw !== '') {
                    $decoded = \json_decode($raw, true);
                } elseif (\is_array($raw)) {
                    $decoded = $raw;
                }
                if (\is_array($decoded)) {
                    $identity = $decoded['scope']['identity'] ?? null;
                    if (\is_array($identity) && \array_key_exists('website_id', $identity)
                        && \is_numeric($identity['website_id'])
                    ) {
                        return \max(0, (int)$identity['website_id']);
                    }
                }
            }
        } catch (\Throwable) {
        }
        try {
            return \max(0, RequestContext::getWelineWebsiteId());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param list<array<string, mixed>> $configuredItems
     * @return array{
     *     items: list<array{image:string,title:string,text:string,link:string,link_label:string}>,
     *     looks_source: string,
     *     using_placeholders: bool
     * }
     */
    public function assemble(
        int $websiteId,
        int $columns,
        array $configuredItems,
        bool $isChineseLocale = true,
    ): array {
        $websiteId = \max(0, $websiteId);
        $want = \max(1, $columns);
        $liveItems = $this->fetchLiveItems($want, $websiteId);

        if ($liveItems !== []) {
            $items = $liveItems;
            $looksSource = 'review';
            if (\count($items) < $want && $configuredItems !== []) {
                $filled = $this->fillFromConfigured($items, $configuredItems, $websiteId, $want);
                if (\count($filled) > \count($liveItems)) {
                    $looksSource = 'review+config';
                }
                $items = $filled;
            }

            return [
                'items' => $items,
                'looks_source' => $looksSource,
                'using_placeholders' => false,
            ];
        }

        if ($configuredItems !== []) {
            $filtered = $this->filterConfiguredForWebsite($configuredItems, $websiteId);
            if ($filtered !== []) {
                return [
                    'items' => $filtered,
                    'looks_source' => 'config',
                    'using_placeholders' => false,
                ];
            }
        }

        return [
            'items' => $this->placeholderItems($isChineseLocale),
            'looks_source' => 'placeholder',
            'using_placeholders' => true,
        ];
    }

    /**
     * Drop CTA product links that are not sellable on the current website.
     */
    public function resolveCtaHref(string $ctaLink, int $websiteId): string
    {
        $ctaLink = \trim($ctaLink);
        if ($ctaLink === '' || \preg_match('#/product/(\d+)#', $ctaLink, $ctaMatch) !== 1) {
            return $ctaLink;
        }
        $ctaProductId = (int)$ctaMatch[1];
        if ($ctaProductId <= 0) {
            return '';
        }
        $existing = $this->existingProductIds($websiteId, [$ctaProductId]);
        if (!isset($existing[$ctaProductId])) {
            return '';
        }
        $sellable = $this->sellableProductIds([$ctaProductId]);
        if (!isset($sellable[$ctaProductId])) {
            return '';
        }

        return $ctaLink;
    }

    /**
     * @return list<array{image:string,title:string,text:string,link:string,link_label:string}>
     */
    private function fetchLiveItems(int $limit, int $websiteId): array
    {
        $looksGallery = null;
        if (\interface_exists(\Weline\Review\Api\BuyerLooksGalleryInterface::class)) {
            try {
                $looksGallery = ObjectManager::getInstance(
                    \Weline\Review\Api\BuyerLooksGalleryInterface::class
                );
            } catch (\Throwable) {
                $looksGallery = null;
            }
        }
        if ($looksGallery === null && \class_exists(\Weline\Review\Service\BuyerLooksGalleryService::class)) {
            try {
                $looksGallery = ObjectManager::getInstance(
                    \Weline\Review\Service\BuyerLooksGalleryService::class
                );
            } catch (\Throwable) {
                $looksGallery = null;
            }
        }
        if ($looksGallery === null || !\method_exists($looksGallery, 'galleryItems')) {
            return [];
        }

        $liveItems = [];
        try {
            $liveRaw = $looksGallery->galleryItems($limit, $websiteId);
            if (!\is_array($liveRaw)) {
                return [];
            }
            foreach ($liveRaw as $liveRow) {
                if (!\is_array($liveRow)) {
                    continue;
                }
                $liveImage = \trim((string)($liveRow['image'] ?? ''));
                if ($liveImage === '') {
                    continue;
                }
                $liveItems[] = [
                    'image' => $liveImage,
                    'title' => (string)($liveRow['title'] ?? ''),
                    'text' => (string)($liveRow['text'] ?? ''),
                    'link' => (string)($liveRow['link'] ?? ''),
                    'link_label' => (string)($liveRow['link_label'] ?? '查看商品'),
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        return $liveItems;
    }

    /**
     * @param list<array{image:string,title:string,text:string,link:string,link_label:string}> $items
     * @param list<array<string, mixed>> $configuredItems
     * @return list<array{image:string,title:string,text:string,link:string,link_label:string}>
     */
    private function fillFromConfigured(
        array $items,
        array $configuredItems,
        int $websiteId,
        int $want,
    ): array {
        $filtered = $this->filterConfiguredForWebsite($configuredItems, $websiteId);
        if ($filtered === []) {
            return $items;
        }
        $seen = [];
        $seenProducts = [];
        foreach ($items as $row) {
            $img = \trim((string)($row['image'] ?? ''));
            if ($img !== '') {
                $seen[$img] = true;
            }
            $link = \trim((string)($row['link'] ?? ''));
            if ($link !== '' && \preg_match('#/product/(\d+)#', $link, $m) === 1) {
                $seenProducts[(int)$m[1]] = true;
            }
        }
        foreach ($filtered as $cfg) {
            if (\count($items) >= $want) {
                break;
            }
            $img = \trim((string)($cfg['image'] ?? ''));
            if ($img === '' || isset($seen[$img])) {
                continue;
            }
            $link = \trim((string)($cfg['link'] ?? ''));
            if ($link !== '' && \preg_match('#/product/(\d+)#', $link, $m) === 1 && isset($seenProducts[(int)$m[1]])) {
                continue;
            }
            $seen[$img] = true;
            if ($link !== '' && \preg_match('#/product/(\d+)#', $link, $m) === 1) {
                $seenProducts[(int)$m[1]] = true;
            }
            $items[] = [
                'image' => $img,
                'title' => (string)($cfg['title'] ?? ''),
                'text' => (string)($cfg['text'] ?? ''),
                'link' => $link,
                'link_label' => (string)($cfg['link_label'] ?? '查看商品'),
            ];
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{image:string,title:string,text:string,link:string,link_label:string}>
     */
    private function filterConfiguredForWebsite(array $rows, int $websiteId): array
    {
        if ($rows === []) {
            return [];
        }

        $candidates = [];
        $productIds = [];
        foreach ($rows as $cfg) {
            if (!\is_array($cfg)) {
                continue;
            }
            $link = \trim((string)($cfg['link'] ?? ''));
            if ($link === '' || \preg_match('#/product/(\d+)#', $link, $m) !== 1) {
                // Unverifiable looks row — never pass through as cross-site seed.
                continue;
            }
            $productId = (int)$m[1];
            if ($productId <= 0) {
                continue;
            }
            $candidates[] = [
                'cfg' => $cfg,
                'product_id' => $productId,
                'link' => $link,
            ];
            $productIds[$productId] = $productId;
        }
        if ($candidates === []) {
            return [];
        }

        $existing = $this->existingProductIds($websiteId, \array_values($productIds));
        if ($existing === []) {
            return [];
        }
        $sellable = $this->sellableProductIds(\array_keys($existing));

        $out = [];
        foreach ($candidates as $candidate) {
            $productId = $candidate['product_id'];
            if (!isset($existing[$productId]) || !isset($sellable[$productId])) {
                continue;
            }
            $cfg = $candidate['cfg'];
            $out[] = [
                'image' => \trim((string)($cfg['image'] ?? '')),
                'title' => (string)($cfg['title'] ?? ''),
                'text' => (string)($cfg['text'] ?? ''),
                'link' => $candidate['link'],
                'link_label' => (string)($cfg['link_label'] ?? '查看商品'),
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, true>
     */
    private function existingProductIds(int $websiteId, array $productIds): array
    {
        $productIds = \array_values(\array_unique(\array_filter(
            \array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($productIds === [] || !\class_exists(\Weline\Product\Repository\ProductRepository::class)) {
            return [];
        }
        try {
            /** @var \Weline\Product\Repository\ProductRepository $products */
            $products = ObjectManager::getInstance(\Weline\Product\Repository\ProductRepository::class);
            if (\method_exists($products, 'listByIds')) {
                $rows = $products->listByIds($websiteId, $productIds);
                $out = [];
                foreach (\is_array($rows) ? $rows : [] as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $id = (int)($row['product_id'] ?? $row['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $out[$id] = true;
                }

                return $out;
            }
            $out = [];
            foreach ($productIds as $productId) {
                if ($products->findById($websiteId, $productId) !== null) {
                    $out[$productId] = true;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<int> $productIds
     * @return array<int, true>
     */
    private function sellableProductIds(array $productIds): array
    {
        $productIds = \array_values(\array_unique(\array_filter(
            \array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($productIds === []) {
            return [];
        }
        if (!\class_exists(\Weline\Product\Service\StorefrontCatalogProductVisibility::class)) {
            return \array_fill_keys($productIds, true);
        }
        try {
            /** @var \Weline\Product\Service\StorefrontCatalogProductVisibility $gate */
            $gate = ObjectManager::getInstance(
                \Weline\Product\Service\StorefrontCatalogProductVisibility::class
            );
            $allowed = $gate->filterSellableProductIds($productIds);

            return \array_fill_keys($allowed, true);
        } catch (\Throwable) {
            // Fail closed for looks PDP links when the catalog gate cannot run.
            return [];
        }
    }

    /**
     * @return list<array{image:string,title:string,text:string,link:string,link_label:string}>
     */
    private function placeholderItems(bool $isChineseLocale): array
    {
        $placeholder = StorefrontImagePlaceholder::url();
        $lookScenes = [
            ['title' => '礼宴试穿', 'title_en' => 'Formal try-on'],
            ['title' => '日常通勤', 'title_en' => 'Everyday commute'],
            ['title' => '旅拍街景', 'title_en' => 'Travel street look'],
            ['title' => '节日聚会', 'title_en' => 'Festival gathering'],
            ['title' => '校园问候', 'title_en' => 'Campus greeting'],
            ['title' => '雅集留影', 'title_en' => 'Gathering snapshot'],
        ];
        $items = [];
        foreach ($lookScenes as $scene) {
            $items[] = [
                'image' => $placeholder,
                'title' => $this->translateDefaultCopy($scene['title'], $scene['title_en'], $isChineseLocale),
                'text' => '',
                'link' => '',
                'link_label' => '查看商品',
            ];
        }

        return $items;
    }

    private function translateDefaultCopy(string $source, string $english, bool $isChineseLocale): string
    {
        $translated = \trim((string)WidgetI18n::label($source));
        if ($translated !== '' && $translated !== $source) {
            return $translated;
        }

        return $isChineseLocale ? $source : $english;
    }
}
