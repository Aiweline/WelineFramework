<?php

declare(strict_types=1);

namespace Weline\Promotion\Service;

use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Promotion\Model\PromotionActivityTheme;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;
use Weline\Websites\Model\Website;

/**
 * 活动主题 ResourceChange Producer。
 * CDN / SEO 等外部副作用由 Weline_Framework::resource_changed 接收方处理；
 * 本类只负责在主库事务内发布契约信封。
 */
final class PromotionActivityThemeResourceChangePublisher
{
    public const RESOURCE_TYPE = 'promotion_activity_theme';

    public function __construct(
        private readonly ResourceRevisionService $revisions,
        private readonly ResourceChangeFactory $changes,
        private readonly NamespacePath $namespacePath,
        private readonly WebsiteCatalogInterface $websites,
    ) {
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public function publishUpsert(array $before, array $after, string $entry = 'promotion.theme.save'): ResourceChange
    {
        $themeId = (int)($after[PromotionActivityTheme::schema_fields_ID] ?? 0);
        if ($themeId <= 0) {
            throw new \LogicException((string)__('活动主题 ResourceChange 缺少有效 id'));
        }

        $websiteId = max(0, (int)($after[PromotionActivityTheme::schema_fields_WEBSITE_ID] ?? 0));
        $websiteCode = $this->resolveWebsiteCode($websiteId);
        $beforeSnapshot = $this->snapshot($before);
        $afterSnapshot = $this->snapshot($after);
        $urls = $this->storefrontUrls($afterSnapshot);
        $previousUrls = $this->storefrontUrls($beforeSnapshot);
        $previousOnly = array_values(array_diff($previousUrls, $urls));

        $revision = $this->revisions->next(self::RESOURCE_TYPE, $themeId);
        $change = $this->changes->create(
            resourceType: self::RESOURCE_TYPE,
            resourceId: $themeId,
            action: 'upsert',
            revision: $revision,
            websiteId: $websiteId,
            websiteCode: $websiteCode,
            before: $beforeSnapshot,
            after: $afterSnapshot,
            changedFields: $this->changedFields($beforeSnapshot, $afterSnapshot),
            impact: [
                'namespaces' => [
                    $this->namespacePath->website($websiteCode, ['promotion']),
                    $this->namespacePath->website($websiteCode, ['promotion', (string)$themeId]),
                ],
                'previous_namespaces' => $this->previousNamespaces($beforeSnapshot, $websiteCode, $themeId),
                'urls' => $urls,
                'previous_urls' => $previousOnly,
            ],
            origin: ['entry' => $entry],
            siteId: $websiteId,
        );
        w_changed($change);

        return $change;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function snapshot(array $row): array
    {
        $fields = [
            PromotionActivityTheme::schema_fields_ID,
            PromotionActivityTheme::schema_fields_THEME_KEY,
            PromotionActivityTheme::schema_fields_PAGE_SLUG,
            PromotionActivityTheme::schema_fields_WEBSITE_ID,
            PromotionActivityTheme::schema_fields_STORE_CODE,
            PromotionActivityTheme::schema_fields_CHANNEL_CODE,
            PromotionActivityTheme::schema_fields_STATUS,
            PromotionActivityTheme::schema_fields_SORT_ORDER,
            PromotionActivityTheme::schema_fields_IS_NAV_TAB,
            PromotionActivityTheme::schema_fields_PRICE_BAND,
            PromotionActivityTheme::schema_fields_PRODUCT_PICK_MODE,
            PromotionActivityTheme::schema_fields_PRODUCT_FILTER_JSON,
        ];
        $snapshot = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $snapshot[$field] = $row[$field];
            }
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $row @return list<string> */
    public function storefrontUrls(array $row): array
    {
        $slug = strtolower(trim((string)($row[PromotionActivityTheme::schema_fields_PAGE_SLUG] ?? '')));
        $urls = ['/promotion'];
        if ($slug !== '') {
            $urls[] = '/promotion/' . rawurlencode($slug);
        }

        return array_values(array_unique($urls));
    }

    /** @param array<string,mixed>|null $after @return list<string> */
    private function changedFields(array $before, ?array $after): array
    {
        $fields = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after ?? []))) as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $fields[] = (string)$field;
            }
        }
        sort($fields, SORT_STRING);

        return $fields === [] ? [PromotionActivityTheme::schema_fields_STATUS] : $fields;
    }

    /** @param array<string,mixed> $before @return list<string> */
    private function previousNamespaces(array $before, string $currentWebsiteCode, int $themeId): array
    {
        if ($before === []) {
            return [];
        }
        $beforeWebsiteId = max(0, (int)($before[PromotionActivityTheme::schema_fields_WEBSITE_ID] ?? 0));
        $beforeCode = $this->resolveWebsiteCode($beforeWebsiteId);
        if ($beforeCode === $currentWebsiteCode) {
            return [];
        }

        return [
            $this->namespacePath->website($beforeCode, ['promotion']),
            $this->namespacePath->website($beforeCode, ['promotion', (string)$themeId]),
        ];
    }

    private function resolveWebsiteCode(int $websiteId): string
    {
        if ($websiteId === Website::ID_DEFAULT) {
            return Website::CODE_DEFAULT;
        }
        foreach ($this->websites->all() as $website) {
            if ((int)$website->id === $websiteId && trim($website->code) !== '') {
                return trim($website->code);
            }
        }

        return $websiteId === 0 ? Website::CODE_DEFAULT : ('w' . $websiteId);
    }
}
