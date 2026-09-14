<?php

declare(strict_types=1);

namespace Weline\Promotion\Service;

use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Promotion\Model\PromotionActivityTheme;
use Weline\Promotion\Model\PromotionActivityThemeLocal;

final class PromotionActivityThemeService
{
    public function __construct(
        private readonly PromotionActivityTheme $theme,
        private readonly PromotionActivityThemeLocal $themeLocal,
        private readonly PromotionScopeResolver $scopeResolver,
        private readonly PromotionThemeProductService $themeProductService,
        private readonly PromotionActivityThemeResourceChangePublisher $resourceChanges,
        private readonly PromotionStorefrontCacheInvalidator $storefrontCache,
        private readonly PromotionThemeDealDiscountSyncService $dealDiscountSync,
        private readonly Url $url,
    ) {
    }

    public function storefrontUrl(string $pageSlug = ''): string
    {
        return $this->url->getFrontendUrl(self::storefrontPath($pageSlug));
    }

    /** Origin-free route for scope-shared product data. */
    public static function storefrontPath(string $pageSlug = ''): string
    {
        $pageSlug = strtolower(trim($pageSlug));
        if ($pageSlug === '' || $pageSlug === 'index') {
            return 'promotion';
        }

        return 'promotion/' . rawurlencode($pageSlug);
    }

    public function hasActivePage(string $pageSlug): bool
    {
        return $this->findActiveByPageSlug(strtolower(trim($pageSlug)), $this->scopeResolver->resolve()) !== null;
    }

    /** @return array<string, mixed> */
    public function buildStorefrontPage(string $pageSlug): array
    {
        $scope = $this->scopeResolver->resolve();
        $theme = $this->findActiveByPageSlug($pageSlug, $scope);
        if ($theme === null) {
            return [];
        }

        $copy = $this->resolveLocalizedCopy((int)$theme['id'], $theme);

        return [
            'theme_key' => (string)$theme['theme_key'],
            'page_slug' => (string)$theme['page_slug'],
            'page_type' => (string)$theme['page_slug'],
            'title' => (string)$copy['page_title'],
            'page_title' => (string)$copy['page_title'],
            'hero_lede' => (string)$copy['hero_lede'],
            'price_band' => (string)$theme['price_band'],
            'product_pick_mode' => (string)$theme['product_pick_mode'],
            'deal_discount_type' => (string)($theme[PromotionActivityTheme::schema_fields_DEAL_DISCOUNT_TYPE] ?? $theme['deal_discount_type'] ?? PromotionThemeDealDiscountSyncService::DISCOUNT_NONE),
            'deal_discount_value' => (float)($theme[PromotionActivityTheme::schema_fields_DEAL_DISCOUNT_VALUE] ?? $theme['deal_discount_value'] ?? 0),
            'marketing_rule_id' => (int)($theme[PromotionActivityTheme::schema_fields_MARKETING_RULE_ID] ?? $theme['marketing_rule_id'] ?? 0),
            'product_ids' => $this->themeProductService->resolveStorefrontProductIds($theme, $scope),
            'theme_id' => (int)$theme['id'],
            'id' => (int)$theme['id'],
            'scope' => [
                'website_id' => (int)$theme['website_id'],
                'store_code' => (string)$theme['store_code'],
                'channel_code' => (string)$theme['channel_code'],
            ],
        ];
    }

    /** Active theme id for a storefront page_slug in the current request scope (0 when none). */
    public function resolveActiveThemeIdByPageSlug(string $pageSlug): int
    {
        $pageSlug = strtolower(trim($pageSlug));
        if ($pageSlug === '' || $pageSlug === 'index') {
            return 0;
        }
        $theme = $this->findActiveByPageSlug($pageSlug, $this->scopeResolver->resolve());

        return $theme === null ? 0 : max(0, (int)($theme['id'] ?? 0));
    }

    /** @return list<array<string, mixed>> */
    public function listNavTabs(): array
    {
        $tabs = [
            [
                'slug' => 'index',
                'label' => (string)__('活动首页'),
                'url' => $this->storefrontUrl(),
                'active_key' => 'index',
            ],
        ];

        foreach ($this->listActiveThemes($this->scopeResolver->resolve()) as $theme) {
            if ((int)($theme['is_nav_tab'] ?? 0) !== 1) {
                continue;
            }
            $copy = $this->resolveLocalizedCopy((int)$theme['id'], $theme);
            $slug = (string)$theme['page_slug'];
            $label = (string)($copy['nav_label'] ?: $copy['page_title'] ?: $this->defaultNavLabel($slug));
            if ($label === '') {
                continue;
            }
            $tabs[] = [
                'slug' => $slug,
                'label' => $label,
                'url' => $this->storefrontUrl($slug),
                'active_key' => $slug,
            ];
        }

        return $tabs;
    }

    /** @return list<array<string, string>> */
    public function listEntryCards(string $currentPageSlug): array
    {
        $cards = [];
        foreach ($this->listActiveThemes($this->scopeResolver->resolve()) as $theme) {
            $slug = (string)$theme['page_slug'];
            if ($currentPageSlug !== 'index' && $slug === $currentPageSlug) {
                continue;
            }
            $copy = $this->resolveLocalizedCopy((int)$theme['id'], $theme);
            $cards[] = [
                'title' => (string)$copy['entry_title'],
                'subtitle' => (string)$copy['entry_subtitle'],
                'action_label' => (string)$copy['entry_action_label'],
                'action_url' => $this->storefrontUrl($slug),
            ];
        }

        return $cards;
    }

    /** @return list<array<string, mixed>> */
    public function listForBackend(?array $filters = null): array
    {
        $scopeFilter = $this->resolveApplicableScopeFilter($filters);

        $collection = clone $this->theme;
        $collection->clear()->order(PromotionActivityTheme::schema_fields_SORT_ORDER, 'ASC')
            ->order(PromotionActivityTheme::schema_fields_ID, 'ASC')
            ->select()
            ->fetch();

        $items = [];
        foreach ($collection->getItems() as $item) {
            $row = $this->normalizeThemeRow($item->getData());
            if ($scopeFilter !== null && !PromotionActivityThemeScopeMatcher::matches($row, $scopeFilter)) {
                continue;
            }
            if ($filters !== null && !$this->matchesAdminFilter($row, $filters)) {
                continue;
            }
            $items[] = $row + $this->resolveLocalizedCopy((int)$row['id'], $row) + [
                'product_ids' => $this->themeProductService->listProductIds((int)$row['id']),
                'product_filter' => $this->themeProductService->decodeFilterJson((string)$row['product_filter_json']),
                'selected_products' => $this->themeProductService->listProductsForBackend((int)$row['id'], [
                    'website_id' => (int)$row['website_id'],
                    'store_code' => (string)$row['store_code'],
                    'channel_code' => (string)$row['channel_code'],
                ]),
            ];
        }

        return $items;
    }

    /** @param array<string, mixed> $data */
    public function saveTheme(array $data): array
    {
        $connection = $this->theme->getConnection();
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        if (!$transactions->isActive($connection)) {
            return $transactions->runWrite(
                $connection,
                fn (): array => $this->saveTheme($data),
            );
        }

        $themeKey = strtolower(trim((string)($data['theme_key'] ?? '')));
        $pageSlug = strtolower(trim((string)($data['page_slug'] ?? '')));
        if ($themeKey === '' || $pageSlug === '') {
            return ['success' => false, 'message' => (string)__('主题键与路径 slug 不能为空。')];
        }
        if (!preg_match('/^[a-z0-9_-]{2,64}$/', $pageSlug)) {
            return ['success' => false, 'message' => (string)__('路径 slug 仅允许小写字母、数字、下划线或连字符。')];
        }

        $status = strtolower(trim((string)($data['status'] ?? PromotionActivityTheme::STATUS_DRAFT)));
        if (!in_array($status, PromotionActivityTheme::allowedStatuses(), true)) {
            return ['success' => false, 'message' => (string)__('不支持的主题状态。')];
        }

        $websiteRaw = $data['website_id'] ?? null;
        if ($websiteRaw === null || $websiteRaw === '') {
            return ['success' => false, 'message' => (string)__('活动主题必须指定 Website（一站一活动）。')];
        }
        $websiteId = (int)$websiteRaw;
        if ($websiteId < 0) {
            return ['success' => false, 'message' => (string)__('活动主题 Website 无效。')];
        }
        $storeCode = trim((string)($data['store_code'] ?? ''));
        $channelCode = trim((string)($data['channel_code'] ?? ''));
        if ($channelCode !== '' && $storeCode === '') {
            return ['success' => false, 'message' => (string)__('配置渠道范围时必须同时指定店铺 code。')];
        }

        $pickMode = strtolower(trim((string)($data['product_pick_mode'] ?? PromotionThemeProductService::PICK_MODE_MANUAL)));
        if (!in_array($pickMode, PromotionThemeProductService::allowedPickModes(), true)) {
            $pickMode = PromotionThemeProductService::PICK_MODE_MANUAL;
        }

        $scope = [
            'website_id' => $websiteId,
            'store_code' => $storeCode,
            'channel_code' => $channelCode,
        ];

        $productIds = $data['product_ids'] ?? [];
        if (!is_array($productIds)) {
            $productIds = array_filter(array_map('intval', explode(',', (string)$productIds)));
        }
        $productWebsiteIds = $data['product_website_ids'] ?? [];
        if (!is_array($productWebsiteIds)) {
            $productWebsiteIds = array_filter(array_map('intval', explode(',', (string)$productWebsiteIds)));
        }

        if ($pickMode !== PromotionThemeProductService::PICK_MODE_FILTER) {
            $selectionCheck = $this->themeProductService->validateSelection(
                $productIds,
                $scope,
                $pickMode,
                $productWebsiteIds,
            );
            if (!($selectionCheck['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string)($selectionCheck['message'] ?? __('保存失败。')),
                ];
            }
        }

        $dealType = $this->dealDiscountSync->normalizeType((string)($data['deal_discount_type'] ?? PromotionThemeDealDiscountSyncService::DISCOUNT_NONE));
        $dealValue = round(max(0, (float)($data['deal_discount_value'] ?? 0)), 2);
        $forceOverlap = !empty($data['force_overlap']) || !empty($data['force_product_overlap']);
        if (
            !$forceOverlap
            && $dealType !== PromotionThemeDealDiscountSyncService::DISCOUNT_NONE
            && $dealValue > 0
        ) {
            $overlapProductIds = array_values(array_map('intval', is_array($productIds) ? $productIds : []));
            if ($pickMode === PromotionThemeProductService::PICK_MODE_FILTER) {
                $filterPreview = $this->themeProductService->previewFilterProducts([
                    'website_id' => $websiteId,
                    'store_code' => $storeCode,
                    'channel_code' => $channelCode,
                    'filter_product_type' => (string)(($data['filter_product_type'] ?? '') ?: ''),
                    'filter_status' => (string)(($data['filter_status'] ?? 'published') ?: 'published'),
                    'filter_name' => (string)($data['filter_name'] ?? ''),
                    'filter_sku' => (string)($data['filter_sku'] ?? ''),
                    'filter_product_code' => (string)($data['filter_product_code'] ?? ''),
                    'filter_new_within_days' => (int)($data['filter_new_within_days'] ?? 0),
                    'filter_limit' => (int)($data['filter_limit'] ?? 12),
                    'page' => 1,
                    'page_size' => 48,
                ]);
                $overlapProductIds = [];
                foreach (($filterPreview['items'] ?? []) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $pid = (int)($item['product_id'] ?? $item['id'] ?? 0);
                    if ($pid > 0) {
                        $overlapProductIds[] = $pid;
                    }
                }
            }
            $overlaps = $this->themeProductService->findActiveDealOverlaps(
                $overlapProductIds,
                $scope,
                $id,
            );
            if ($overlaps !== []) {
                return [
                    'success' => false,
                    'needs_overlap_confirm' => true,
                    'overlaps' => $overlaps,
                    'message' => (string)__('以下 SKU 已出现在其他真实折扣活动中。确认后前台将允许顾客选择参考哪个活动（默认显示最强折扣）。'),
                ];
            }
        }

        $model = clone $this->theme;
        $id = (int)($data['id'] ?? 0);
        $before = [];
        if ($id > 0) {
            $model->clear()->where(PromotionActivityTheme::schema_fields_ID, $id)->find()->fetch();
            if ($model->getId()) {
                $before = is_array($model->getData()) ? $model->getData() : [];
            }
        }
        if (!$model->getId()) {
            $model = clone $this->theme;
            $model->clearData();
        }

        $model->setData(PromotionActivityTheme::schema_fields_THEME_KEY, $themeKey);
        $model->setData(PromotionActivityTheme::schema_fields_PAGE_SLUG, $pageSlug);
        $model->setData(PromotionActivityTheme::schema_fields_WEBSITE_ID, $websiteId);
        $model->setData(PromotionActivityTheme::schema_fields_STORE_CODE, $storeCode);
        $model->setData(PromotionActivityTheme::schema_fields_CHANNEL_CODE, $channelCode);
        $model->setData(PromotionActivityTheme::schema_fields_STATUS, $status);
        $model->setData(PromotionActivityTheme::schema_fields_SORT_ORDER, (int)($data['sort_order'] ?? 0));
        $model->setData(PromotionActivityTheme::schema_fields_IS_NAV_TAB, !empty($data['is_nav_tab']) ? 1 : 0);
        $model->setData(PromotionActivityTheme::schema_fields_PRICE_BAND, trim((string)($data['price_band'] ?? '')));
        $model->setData(PromotionActivityTheme::schema_fields_PRODUCT_PICK_MODE, $pickMode);
        $model->setData(PromotionActivityTheme::schema_fields_DEAL_DISCOUNT_TYPE, $dealType);
        $model->setData(PromotionActivityTheme::schema_fields_DEAL_DISCOUNT_VALUE, $dealValue);
        $model->setData(
            PromotionActivityTheme::schema_fields_PRODUCT_FILTER_JSON,
            $pickMode === PromotionThemeProductService::PICK_MODE_FILTER
                ? $this->themeProductService->encodeFilterJson($data)
                : ''
        );
        $model->save();

        $this->saveLocalizedCopy((int)$model->getId(), $data);

        $this->themeProductService->saveProducts(
            (int)$model->getId(),
            $productIds,
            $scope,
            $pickMode,
            $productWebsiteIds,
        );

        $themeId = (int)$model->getId();
        $themeRow = is_array($model->getData()) ? $model->getData() : [];
        $syncedProductIds = [];
        try {
            $syncedProductIds = $this->themeProductService->resolveStorefrontProductIds($themeRow, $scope);
        } catch (\Throwable) {
            $syncedProductIds = array_values(array_filter(array_map('intval', is_array($productIds) ? $productIds : [])));
        }
        $sync = ['rule_id' => 0, 'skus' => []];
        try {
            $sync = $this->dealDiscountSync->sync(
                $themeRow + [
                    'deal_discount_type' => $dealType,
                    'deal_discount_value' => $dealValue,
                ],
                $syncedProductIds,
            );
        } catch (\Throwable) {
            $sync = ['rule_id' => 0, 'skus' => []];
        }
        if ((int)($sync['rule_id'] ?? 0) > 0) {
            $model->clear()->where(PromotionActivityTheme::schema_fields_ID, $themeId)->find()->fetch();
        }

        $after = is_array($model->getData()) ? $model->getData() : [];
        $this->resourceChanges->publishUpsert($before, $after, 'promotion.theme.save');
        $invalidatedUrls = array_values(array_unique(array_merge(
            $this->resourceChanges->storefrontUrls($before),
            $this->resourceChanges->storefrontUrls($after),
        )));

        $eventData = [
            'theme_id' => $themeId,
            'website_id' => $websiteId,
            'page_slug' => $pageSlug,
            'urls' => $invalidatedUrls,
            'before' => $before,
            'after' => $after,
        ];
        ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class)->dispatch(
            'Weline_Promotion::theme_save_after',
            $eventData,
        );

        $transactions->afterCommit(
            $connection,
            'promotion_theme_storefront_cache_' . $themeId,
            function () use ($themeId): void {
                $this->storefrontCache->clearForTheme('promotion_theme_save_' . $themeId);
            },
        );

        return [
            'success' => true,
            'item' => $this->normalizeThemeRow($model->getData())
                + $this->resolveLocalizedCopy((int)$model->getId(), $model->getData()),
            'invalidated_urls' => $invalidatedUrls,
        ];
    }

    public function ensureDefaultThemes(): void
    {
        $defaults = [
            [
                'theme_key' => 'deals',
                'page_slug' => 'deals',
                'sort_order' => 10,
                'price_band' => 'under_200',
                // 今日特价（deals）≠ 今日精选（featured 精选陈列）；活动标与顶栏同源。
                'nav_label' => '今日特价',
                'page_title' => '今日特价专场',
                'hero_lede' => '从价格友好的配饰、发冠与日常常服单品开始，引导买家进入购物车和结账路径。',
                'entry_title' => '轻量搭配入口',
                'entry_subtitle' => '从价格友好的配饰、发冠与日常常服单品开始，引导买家进入购物车和结账路径。',
                'entry_action_label' => '看今日特价',
            ],
            [
                'theme_key' => 'sale',
                'page_slug' => 'sale',
                'sort_order' => 20,
                'price_band' => '300_plus',
                'nav_label' => '节令主题',
                'page_title' => '节令主题陈列',
                'hero_lede' => '围绕传统节令与仪式场景，陈列节庆礼服与主题套装，展示真实成交价，不配置额外优惠。',
                'entry_title' => '节令主题搭配',
                'entry_subtitle' => '围绕传统节令与仪式场景，陈列节庆礼服与主题套装，不虚构折扣。',
                'entry_action_label' => '看节令主题',
            ],
            [
                'theme_key' => 'weekend',
                'page_slug' => 'weekend',
                'sort_order' => 30,
                'price_band' => 'under_200',
                'nav_label' => '出游常服',
                'page_title' => '出游常服专场',
                'hero_lede' => '围绕踏青、市集与日常出游，陈列常服套装与轻便搭配，只展示真实可售商品，不虚构折扣。',
                'entry_title' => '出游常服',
                'entry_subtitle' => '适合踏青、市集打卡与日常出行的常服套装与轻便搭配。',
                'entry_action_label' => '看出游常服',
            ],
            [
                'theme_key' => 'wedding',
                'page_slug' => 'wedding',
                'sort_order' => 40,
                'price_band' => '300_plus',
                'nav_label' => '婚嫁礼服',
                'page_title' => '婚嫁礼服陈列',
                'hero_lede' => '围绕婚礼、订婚与敬酒仪式，陈列嫁衣与礼服套装，只展示真实成交价，不虚构折扣。',
                'entry_title' => '婚嫁礼服',
                'entry_subtitle' => '婚礼嫁衣与仪式礼服主题陈列，便于挑款与搭配。',
                'entry_action_label' => '看婚嫁礼服',
            ],
        ];

        $this->retireGiftThemes();

        foreach ($defaults as $row) {
            foreach ($this->defaultThemeWebsiteIds() as $websiteId) {
                $existing = clone $this->theme;
                $existing->clear()
                    ->where(PromotionActivityTheme::schema_fields_PAGE_SLUG, $row['page_slug'])
                    ->where(PromotionActivityTheme::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(PromotionActivityTheme::schema_fields_STORE_CODE, '')
                    ->where(PromotionActivityTheme::schema_fields_CHANNEL_CODE, '')
                    ->find()
                    ->fetch();
                if ($existing->getId()) {
                    $this->overwriteDefaultLocalizedCopy((int)$existing->getId(), $row);
                    continue;
                }

                $this->saveTheme([
                    'theme_key' => $row['theme_key'],
                    'page_slug' => $row['page_slug'],
                    'website_id' => $websiteId,
                    'store_code' => '',
                    'channel_code' => '',
                    'status' => PromotionActivityTheme::STATUS_ACTIVE,
                    'sort_order' => $row['sort_order'],
                    'is_nav_tab' => 1,
                    'price_band' => $row['price_band'],
                    'deal_discount_type' => PromotionThemeDealDiscountSyncService::DISCOUNT_NONE,
                    'deal_discount_value' => 0,
                    'nav_label' => $row['nav_label'],
                    'page_title' => $row['page_title'],
                    'hero_lede' => $row['hero_lede'],
                    'entry_title' => $row['entry_title'],
                    'entry_subtitle' => $row['entry_subtitle'],
                    'entry_action_label' => $row['entry_action_label'],
                ]);
            }
        }

        $this->migrateLegacyDealsFeaturedBranding();
    }

    /**
     * deals 曾误用「今日精选 / Today's Picks」文案；与顶栏「今日特价」及 featured 精选陈列冲突。
     * 仅改写已知遗留串，不覆盖运营自定义文案。
     */
    private function migrateLegacyDealsFeaturedBranding(): void
    {
        $legacyNav = [
            '今日精选' => '今日特价',
            "Today's Picks" => "Today's Deals",
            "Today's picks" => "Today's Deals",
            'مختارات اليوم' => 'عروض اليوم',
            'الاختيارات المميزة لهذا اليوم' => 'عروض اليوم',
            'Heute empfohlene Artikel' => 'Tagesangebote',
            'Selección del día' => 'Ofertas de hoy',
            'Sélection du jour' => 'Offres du jour',
            '今日の厳選' => '今日の特価',
            '오늘의 추천' => '오늘의 특가',
            'Seleção do dia' => 'Ofertas de hoje',
            'Сегодняшние избранные' => 'Сегодняшние скидки',
            'วันนี้แนะนำ' => 'ดีลวันนี้',
            'Sản phẩm nổi bật hôm nay' => 'Ưu đãi hôm nay',
        ];
        $legacyTitle = [
            '今日搭配精选' => '今日特价专场',
            "Today's Hanfu edit" => "Today's Deals",
            "Today's Featured Looks" => "Today's Deals",
            'أفضل الخيارات اليوم' => 'عروض اليوم',
            'Heute ausgewählte Outfit-Vorschläge' => 'Tagesangebote',
            'Selección de combinaciones para hoy' => 'Ofertas de hoy',
            'Sélection de tenues du jour' => 'Offres du jour',
            '今日のおすすめコーディネート' => '今日の特価',
            '오늘의 추천 스타일' => '오늘의 특가',
            'Sugestões de looks do dia' => 'Ofertas de hoje',
            'Рекомендации по подбору на сегодня' => 'Сегодняшние скидки',
            'วันนี้: เสื้อผ้าที่คัดสรรมาเป็นพิเศษ' => 'ดีลวันนี้',
            'Gợi ý phối đồ hôm nay' => 'Ưu đãi hôm nay',
        ];
        $legacyAction = [
            '看今日精选' => '看今日特价',
            '查看今日精选' => '看今日特价',
            "View today's picks" => "View today's deals",
            "View Today's Picks" => "View today's deals",
            'عرض مختارات اليوم' => 'عرض عروض اليوم',
            'شاهد أفضل العروض لهذا اليوم' => 'عرض عروض اليوم',
            'Heute ausgewählte Highlights ansehen' => 'Tagesangebote ansehen',
            'Ver las mejores opciones de hoy' => 'Ver ofertas de hoy',
            'Voir les meilleures offres du jour' => 'Voir les offres du jour',
            '今日の厳選アイテムを見る' => '今日の特価を見る',
            '오늘의 추천 보기' => '오늘의 특가 보기',
            'Veja as melhores opções de hoje' => 'Ver ofertas de hoje',
            'Посмотреть сегодняшние подборки' => 'Смотреть сегодняшние скидки',
            'ดูรายการแนะนำวันนี้' => 'ดูดีลวันนี้',
            'Xem các lựa chọn hàng ngày' => 'Xem ưu đãi hôm nay',
        ];

        $collection = clone $this->theme;
        $collection->clear()
            ->where(PromotionActivityTheme::schema_fields_PAGE_SLUG, 'deals')
            ->select()
            ->fetch();

        foreach ($collection->getItems() as $item) {
            $themeId = (int)$item->getId();
            if ($themeId <= 0) {
                continue;
            }
            $locals = clone $this->themeLocal;
            $locals->clear()
                ->where(PromotionActivityThemeLocal::schema_fields_ID, $themeId)
                ->select()
                ->fetch();
            foreach ($locals->getItems() as $local) {
                $changed = false;
                $nav = trim((string)$local->getData(PromotionActivityThemeLocal::schema_fields_NAV_LABEL));
                if (isset($legacyNav[$nav])) {
                    $local->setData(PromotionActivityThemeLocal::schema_fields_NAV_LABEL, $legacyNav[$nav]);
                    $changed = true;
                }
                $title = trim((string)$local->getData(PromotionActivityThemeLocal::schema_fields_PAGE_TITLE));
                if (isset($legacyTitle[$title])) {
                    $local->setData(PromotionActivityThemeLocal::schema_fields_PAGE_TITLE, $legacyTitle[$title]);
                    $changed = true;
                }
                $action = trim((string)$local->getData(PromotionActivityThemeLocal::schema_fields_ENTRY_ACTION_LABEL));
                if (isset($legacyAction[$action])) {
                    $local->setData(PromotionActivityThemeLocal::schema_fields_ENTRY_ACTION_LABEL, $legacyAction[$action]);
                    $changed = true;
                }
                if ($changed) {
                    $local->save();
                }
            }
        }
    }

    /** 礼盒活动已下线：暂停全部 gifts 主题并移出导航。 */
    public function retireGiftThemes(): void
    {
        $collection = clone $this->theme;
        $collection->clear()
            ->where(PromotionActivityTheme::schema_fields_PAGE_SLUG, 'gifts')
            ->select()
            ->fetch();

        foreach ($collection->getItems() as $item) {
            $row = $this->normalizeThemeRow($item->getData());
            $themeId = (int)($row['id'] ?? 0);
            if ($themeId <= 0) {
                continue;
            }
            if (
                ($row['status'] ?? '') === PromotionActivityTheme::STATUS_PAUSED
                && (int)($row['is_nav_tab'] ?? 0) === 0
            ) {
                continue;
            }
            $copy = $this->resolveLocalizedCopy($themeId, $row);
            $this->saveTheme([
                'id' => $themeId,
                'theme_key' => (string)$row['theme_key'],
                'page_slug' => 'gifts',
                'website_id' => (int)$row['website_id'],
                'store_code' => (string)$row['store_code'],
                'channel_code' => (string)$row['channel_code'],
                'status' => PromotionActivityTheme::STATUS_PAUSED,
                'sort_order' => (int)$row['sort_order'],
                'is_nav_tab' => 0,
                'price_band' => (string)$row['price_band'],
                'product_pick_mode' => (string)$row['product_pick_mode'],
                'product_filter' => $this->themeProductService->decodeFilterJson((string)$row['product_filter_json']),
                'product_ids' => $this->themeProductService->listProductIds($themeId),
                'deal_discount_type' => PromotionThemeDealDiscountSyncService::DISCOUNT_NONE,
                'deal_discount_value' => 0,
                'nav_label' => (string)($copy['nav_label'] ?? ''),
                'page_title' => (string)($copy['page_title'] ?? ''),
                'hero_lede' => (string)($copy['hero_lede'] ?? ''),
                'entry_title' => (string)($copy['entry_title'] ?? ''),
                'entry_subtitle' => (string)($copy['entry_subtitle'] ?? ''),
                'entry_action_label' => (string)($copy['entry_action_label'] ?? ''),
            ]);
        }
    }

    /** @return list<int> */
    private function defaultThemeWebsiteIds(): array
    {
        try {
            $rows = w_query('websites', 'getWebsiteSelectOptions', [], 'backend');
        } catch (\Throwable) {
            $rows = [];
        }

        $ids = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $raw = $row['value'] ?? $row['website_id'] ?? null;
                if ($raw === null || $raw === '') {
                    continue;
                }
                $websiteId = (int)$raw;
                if ($websiteId < 0) {
                    continue;
                }
                // 含 website_id=0（默认网站）
                $ids[$websiteId] = $websiteId;
            }
        }

        return array_values($ids);
    }

    /** @return array<string, mixed> */
    public function buildNewThemeFormDefaults(): array
    {
        return [
            'id' => 0,
            'theme_key' => 'deals',
            'page_slug' => 'deals',
            'status' => PromotionActivityTheme::STATUS_ACTIVE,
            'website_id' => 0,
            'store_code' => '',
            'channel_code' => '',
            'is_nav_tab' => 1,
            'sort_order' => 10,
            'price_band' => 'under_200',
            'product_pick_mode' => 'manual',
            'product_filter' => ['status' => 'published', 'limit' => 12],
            'nav_label' => (string)__('今日特价'),
            'page_title' => (string)__('今日特价专场'),
            'hero_lede' => (string)__('从价格友好的配饰、发冠与日常常服单品开始，引导买家进入购物车和结账路径。'),
            'entry_title' => (string)__('轻量搭配入口'),
            'entry_subtitle' => (string)__('从价格友好的配饰、发冠与日常常服单品开始，引导买家进入购物车和结账路径。'),
            'entry_action_label' => (string)__('看今日特价'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listActiveThemesForStorefront(?array $scope = null): array
    {
        return $this->listActiveThemes($scope ?? $this->scopeResolver->resolve());
    }

    /**
     * Campaign label/URL for storefront price badges (nav_label → page_title → slug).
     *
     * @param array<string, mixed> $themeFallback
     * @return array{campaign_label:string,campaign_url:string,page_title:string}
     */
    public function resolveStorefrontCampaignMeta(int $themeId, array $themeFallback = []): array
    {
        $themeId = max(0, $themeId);
        $fallback = $themeFallback;
        if ($themeId > 0 && (!isset($fallback['id']) || (int)$fallback['id'] !== $themeId)) {
            $fallback['id'] = $themeId;
        }
        $copy = $themeId > 0
            ? $this->resolveLocalizedCopy($themeId, $fallback)
            : [
                'nav_label' => '',
                'page_title' => '',
            ];
        $slug = strtolower(trim((string)($fallback['page_slug'] ?? $copy['page_slug'] ?? '')));
        $pageTitle = trim((string)($copy['page_title'] ?? ''));
        if ($pageTitle === '' && $slug !== '') {
            $pageTitle = $this->defaultPageTitle($slug);
        }
        $label = $this->resolveCampaignDisplayLabel(
            $slug,
            trim((string)($copy['nav_label'] ?? '')),
            $pageTitle,
        );

        return [
            'campaign_label' => $label,
            'campaign_url' => $slug !== '' ? $this->storefrontUrl($slug) : '',
            'page_title' => $pageTitle,
        ];
    }

    /**
     * Storefront badge/picker label: never expose raw page_slug (e.g. "deals").
     */
    public function resolveCampaignDisplayLabel(
        string $pageSlug,
        string $navLabel = '',
        string $pageTitle = '',
    ): string {
        $slug = strtolower(trim($pageSlug));
        $navLabel = trim($navLabel);
        $pageTitle = trim($pageTitle);
        foreach ([$navLabel, $pageTitle] as $candidate) {
            if ($candidate !== '' && ($slug === '' || strcasecmp($candidate, $slug) !== 0)) {
                return $candidate;
            }
        }
        if ($slug !== '') {
            return $this->defaultNavLabel($slug);
        }

        return $pageTitle !== '' ? $pageTitle : $navLabel;
    }

    /** @param array{website_id:int,store_code:string,channel_code:string} $scope */
    private function findActiveByPageSlug(string $pageSlug, array $scope): ?array
    {
        $pageSlug = strtolower(trim($pageSlug));
        foreach ($this->listActiveThemes($scope) as $theme) {
            if ((string)$theme['page_slug'] === $pageSlug) {
                return $theme;
            }
        }

        return null;
    }

    /** @param array{website_id:int,store_code:string,channel_code:string} $scope @return list<array<string, mixed>> */
    private function listActiveThemes(array $scope): array
    {
        $collection = clone $this->theme;
        $collection->clear()
            ->where(PromotionActivityTheme::schema_fields_STATUS, PromotionActivityTheme::STATUS_ACTIVE)
            ->order(PromotionActivityTheme::schema_fields_SORT_ORDER, 'ASC')
            ->order(PromotionActivityTheme::schema_fields_ID, 'ASC')
            ->select()
            ->fetch();

        $items = [];
        foreach ($collection->getItems() as $item) {
            $row = $this->normalizeThemeRow($item->getData());
            if (!PromotionActivityThemeScopeMatcher::matches($row, $scope)) {
                continue;
            }
            $items[] = $row;
        }

        return PromotionActivityThemeScopeMatcher::dedupeByPageSlug($items);
    }

    /** @param array<string, mixed>|null $filters @return array{website_id:int,store_code:string,channel_code:string}|null */
    private function resolveApplicableScopeFilter(?array $filters): ?array
    {
        if ($filters === null) {
            return null;
        }

        $websiteId = max(0, (int)($filters['website_id'] ?? 0));
        $storeCode = trim((string)($filters['store_code'] ?? ''));
        $channelCode = trim((string)($filters['channel_code'] ?? ''));
        // website_id=0 是默认网站，可作为列表筛选；仅 filters=null 表示不过滤。

        return [
            'website_id' => $websiteId,
            'store_code' => $storeCode,
            'channel_code' => $channelCode,
        ];
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $row */
    private function matchesAdminFilter(array $row, array $filters): bool
    {
        if (array_key_exists('status', $filters) && $filters['status'] !== '' && $filters['status'] !== null) {
            if ((string)$row['status'] !== (string)$filters['status']) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function saveLocalizedCopy(int $themeId, array $payload): void
    {
        if ($themeId <= 0) {
            return;
        }

        $locale = trim((string)(Cookie::getLangLocal() ?: Cookie::getLang() ?: 'zh_Hans_CN'));
        $local = clone $this->themeLocal;
        $local->clear()
            ->where(PromotionActivityThemeLocal::schema_fields_ID, $themeId)
            ->where(PromotionActivityThemeLocal::schema_fields_local_code, $locale)
            ->find()
            ->fetch();

        if (!$local->getData(PromotionActivityThemeLocal::schema_fields_ID)) {
            $local = clone $this->themeLocal;
            $local->clearData();
            $local->setData(PromotionActivityThemeLocal::schema_fields_ID, $themeId);
            $local->setLocalCode($locale);
        }

        foreach ([
            PromotionActivityThemeLocal::schema_fields_NAV_LABEL => 'nav_label',
            PromotionActivityThemeLocal::schema_fields_PAGE_TITLE => 'page_title',
            PromotionActivityThemeLocal::schema_fields_HERO_LEDE => 'hero_lede',
            PromotionActivityThemeLocal::schema_fields_ENTRY_TITLE => 'entry_title',
            PromotionActivityThemeLocal::schema_fields_ENTRY_SUBTITLE => 'entry_subtitle',
            PromotionActivityThemeLocal::schema_fields_ENTRY_ACTION_LABEL => 'entry_action_label',
        ] as $field => $inputKey) {
            if (array_key_exists($inputKey, $payload)) {
                $local->setData($field, trim((string)$payload[$inputKey]));
            }
        }

        $local->save();
    }

    /** @param array<string, mixed> $fallback */
    private function resolveLocalizedCopy(int $themeId, array $fallback): array
    {
        $locale = trim((string)(Cookie::getLangLocal() ?: Cookie::getLang() ?: 'zh_Hans_CN'));
        $local = clone $this->themeLocal;
        $local->clear()
            ->where(PromotionActivityThemeLocal::schema_fields_ID, $themeId)
            ->where(PromotionActivityThemeLocal::schema_fields_local_code, $locale)
            ->find()
            ->fetch();

        $pickLocal = static fn (string $field): string => trim((string)$local->getData($field));

        $copy = [
            'nav_label' => $pickLocal(PromotionActivityThemeLocal::schema_fields_NAV_LABEL),
            'page_title' => $pickLocal(PromotionActivityThemeLocal::schema_fields_PAGE_TITLE),
            'hero_lede' => $pickLocal(PromotionActivityThemeLocal::schema_fields_HERO_LEDE),
            'entry_title' => $pickLocal(PromotionActivityThemeLocal::schema_fields_ENTRY_TITLE),
            'entry_subtitle' => $pickLocal(PromotionActivityThemeLocal::schema_fields_ENTRY_SUBTITLE),
            'entry_action_label' => $pickLocal(PromotionActivityThemeLocal::schema_fields_ENTRY_ACTION_LABEL),
        ];

        $slug = strtolower(trim((string)($fallback['page_slug'] ?? '')));
        if ($this->isBuiltInThemeSlug($slug)) {
            $entryDefaults = $this->defaultEntryCopy($slug);
            $translatedDefaults = [
                'nav_label' => $this->defaultNavLabel($slug),
                'page_title' => $this->defaultPageTitle($slug),
                'hero_lede' => $this->defaultHeroLede($slug),
                'entry_title' => (string)($entryDefaults['entry_title'] ?? ''),
                'entry_subtitle' => (string)($entryDefaults['entry_subtitle'] ?? ''),
                'entry_action_label' => (string)($entryDefaults['entry_action_label'] ?? ''),
            ];
            foreach ($translatedDefaults as $field => $value) {
                if (
                    $value !== ''
                    && ($copy[$field] === '' || !$this->isCopyCompatibleWithLocale($copy[$field], $locale))
                ) {
                    $copy[$field] = $value;
                }
            }
        }

        foreach (array_keys($copy) as $field) {
            if ($copy[$field] === '') {
                $copy[$field] = trim((string)($fallback[$field] ?? ''));
            }
        }

        if ($copy['nav_label'] === '' && $slug !== '') {
            $copy['nav_label'] = $this->defaultNavLabel($slug);
        }
        if ($copy['page_title'] === '' && $slug !== '') {
            $copy['page_title'] = $this->defaultPageTitle($slug);
        }
        if ($copy['hero_lede'] === '' && $slug !== '') {
            $copy['hero_lede'] = $this->defaultHeroLede($slug);
        }
        $entryDefaults = $this->defaultEntryCopy($slug);
        foreach (['entry_title', 'entry_subtitle', 'entry_action_label'] as $field) {
            if ($copy[$field] === '' && ($entryDefaults[$field] ?? '') !== '') {
                $copy[$field] = (string)$entryDefaults[$field];
            }
        }

        if ($locale !== 'zh_Hans_CN' && ($copy['nav_label'] === '' || $copy['page_title'] === '')) {
            $fallbackLocal = clone $this->themeLocal;
            $fallbackLocal->clear()
                ->where(PromotionActivityThemeLocal::schema_fields_ID, $themeId)
                ->where(PromotionActivityThemeLocal::schema_fields_local_code, 'zh_Hans_CN')
                ->find()
                ->fetch();
            if ($fallbackLocal->getData(PromotionActivityThemeLocal::schema_fields_ID)) {
                if ($copy['nav_label'] === '') {
                    $copy['nav_label'] = trim((string)$fallbackLocal->getData(PromotionActivityThemeLocal::schema_fields_NAV_LABEL));
                }
                if ($copy['page_title'] === '') {
                    $copy['page_title'] = trim((string)$fallbackLocal->getData(PromotionActivityThemeLocal::schema_fields_PAGE_TITLE));
                }
                if ($copy['hero_lede'] === '') {
                    $copy['hero_lede'] = trim((string)$fallbackLocal->getData(PromotionActivityThemeLocal::schema_fields_HERO_LEDE));
                }
                if ($copy['entry_title'] === '') {
                    $copy['entry_title'] = trim((string)$fallbackLocal->getData(PromotionActivityThemeLocal::schema_fields_ENTRY_TITLE));
                }
                if ($copy['entry_subtitle'] === '') {
                    $copy['entry_subtitle'] = trim((string)$fallbackLocal->getData(PromotionActivityThemeLocal::schema_fields_ENTRY_SUBTITLE));
                }
                if ($copy['entry_action_label'] === '') {
                    $copy['entry_action_label'] = trim((string)$fallbackLocal->getData(PromotionActivityThemeLocal::schema_fields_ENTRY_ACTION_LABEL));
                }
            }
        }

        return $copy;
    }

    private function isBuiltInThemeSlug(string $pageSlug): bool
    {
        return in_array($pageSlug, ['deals', 'sale', 'weekend', 'wedding'], true);
    }

    private function isCopyCompatibleWithLocale(string $value, string $locale): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $normalizedLocale = strtolower(str_replace('-', '_', trim($locale)));
        if (str_starts_with($normalizedLocale, 'zh')) {
            return preg_match('/\p{Han}/u', $value) === 1;
        }
        if (str_starts_with($normalizedLocale, 'ar')) {
            return preg_match('/\p{Arabic}/u', $value) === 1;
        }
        if (str_starts_with($normalizedLocale, 'en')) {
            return preg_match('/[A-Za-z]/', $value) === 1;
        }

        return true;
    }

    /** @param array<string, mixed> $defaults */
    private function backfillDefaultLocalizedCopy(int $themeId, array $defaults): void
    {
        $this->writeDefaultLocalizedCopy($themeId, $defaults, false);
    }

    /** @param array<string, mixed> $defaults */
    private function overwriteDefaultLocalizedCopy(int $themeId, array $defaults): void
    {
        $this->writeDefaultLocalizedCopy($themeId, $defaults, true);
    }

    /** @param array<string, mixed> $defaults */
    private function writeDefaultLocalizedCopy(int $themeId, array $defaults, bool $overwrite): void
    {
        $locale = 'zh_Hans_CN';
        $local = clone $this->themeLocal;
        $local->clear()
            ->where(PromotionActivityThemeLocal::schema_fields_ID, $themeId)
            ->where(PromotionActivityThemeLocal::schema_fields_local_code, $locale)
            ->find()
            ->fetch();

        $isNew = !$local->getData(PromotionActivityThemeLocal::schema_fields_ID);
        if ($isNew) {
            $local = clone $this->themeLocal;
            $local->clearData();
            $local->setData(PromotionActivityThemeLocal::schema_fields_ID, $themeId);
            $local->setLocalCode($locale);
        }

        $changed = false;
        foreach ([
            PromotionActivityThemeLocal::schema_fields_NAV_LABEL => 'nav_label',
            PromotionActivityThemeLocal::schema_fields_PAGE_TITLE => 'page_title',
            PromotionActivityThemeLocal::schema_fields_HERO_LEDE => 'hero_lede',
            PromotionActivityThemeLocal::schema_fields_ENTRY_TITLE => 'entry_title',
            PromotionActivityThemeLocal::schema_fields_ENTRY_SUBTITLE => 'entry_subtitle',
            PromotionActivityThemeLocal::schema_fields_ENTRY_ACTION_LABEL => 'entry_action_label',
        ] as $field => $inputKey) {
            $current = trim((string)$local->getData($field));
            $fallback = trim((string)($defaults[$inputKey] ?? ''));
            if ($fallback === '') {
                continue;
            }
            if ($overwrite || $current === '') {
                if ($current !== $fallback) {
                    $local->setData($field, $fallback);
                    $changed = true;
                }
            }
        }

        if ($isNew || $changed) {
            $local->save();
        }
    }

    private function defaultNavLabel(string $pageSlug): string
    {
        return match ($pageSlug) {
            'deals' => (string)__('今日特价'),
            'sale' => (string)__('节令主题'),
            'weekend' => (string)__('出游常服'),
            'wedding' => (string)__('婚嫁礼服'),
            default => ucfirst(str_replace(['-', '_'], ' ', $pageSlug)),
        };
    }

    private function defaultPageTitle(string $pageSlug): string
    {
        return match ($pageSlug) {
            'deals' => (string)__('今日特价专场'),
            'sale' => (string)__('节令主题陈列'),
            'weekend' => (string)__('出游常服专场'),
            'wedding' => (string)__('婚嫁礼服陈列'),
            default => (string)__('活动主题'),
        };
    }

    private function defaultHeroLede(string $pageSlug): string
    {
        return match ($pageSlug) {
            'deals' => (string)__('从价格友好的配饰、发冠与日常常服单品开始，引导买家进入购物车和结账路径。'),
            'sale' => (string)__('围绕传统节令与仪式场景，陈列节庆礼服与主题套装，展示真实成交价，不配置额外优惠。'),
            'weekend' => (string)__('围绕踏青、市集与日常出游，陈列常服套装与轻便搭配，只展示真实可售商品，不虚构折扣。'),
            'wedding' => (string)__('围绕婚礼、订婚与敬酒仪式，陈列嫁衣与礼服套装，只展示真实成交价，不虚构折扣。'),
            default => (string)__('浏览活动商品，进入商品详情、购物车与结账路径。本页不展示虚假折扣，只承接真实可售商品。'),
        };
    }

    /** @return array{entry_title:string,entry_subtitle:string,entry_action_label:string} */
    private function defaultEntryCopy(string $pageSlug): array
    {
        return match ($pageSlug) {
            'deals' => [
                'entry_title' => (string)__('轻量搭配入口'),
                'entry_subtitle' => (string)__('从价格友好的配饰、发冠与日常常服单品开始，引导买家进入购物车和结账路径。'),
                'entry_action_label' => (string)__('看今日特价'),
            ],
            'sale' => [
                'entry_title' => (string)__('节令主题搭配'),
                'entry_subtitle' => (string)__('围绕传统节令与仪式场景，陈列节庆礼服与主题套装，不虚构折扣。'),
                'entry_action_label' => (string)__('看节令主题'),
            ],
            'weekend' => [
                'entry_title' => (string)__('出游常服'),
                'entry_subtitle' => (string)__('适合踏青、市集打卡与日常出行的常服套装与轻便搭配。'),
                'entry_action_label' => (string)__('看出游常服'),
            ],
            'wedding' => [
                'entry_title' => (string)__('婚嫁礼服'),
                'entry_subtitle' => (string)__('婚礼嫁衣与仪式礼服主题陈列，便于挑款与搭配。'),
                'entry_action_label' => (string)__('看婚嫁礼服'),
            ],
            default => [
                'entry_title' => '',
                'entry_subtitle' => '',
                'entry_action_label' => (string)__('打开'),
            ],
        };
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeThemeRow(array $data): array
    {
        return [
            'id' => (int)($data[PromotionActivityTheme::schema_fields_ID] ?? 0),
            'theme_key' => (string)($data[PromotionActivityTheme::schema_fields_THEME_KEY] ?? ''),
            'page_slug' => (string)($data[PromotionActivityTheme::schema_fields_PAGE_SLUG] ?? ''),
            'website_id' => (int)($data[PromotionActivityTheme::schema_fields_WEBSITE_ID] ?? 0),
            'store_code' => (string)($data[PromotionActivityTheme::schema_fields_STORE_CODE] ?? ''),
            'channel_code' => (string)($data[PromotionActivityTheme::schema_fields_CHANNEL_CODE] ?? ''),
            'status' => (string)($data[PromotionActivityTheme::schema_fields_STATUS] ?? ''),
            'sort_order' => (int)($data[PromotionActivityTheme::schema_fields_SORT_ORDER] ?? 0),
            'is_nav_tab' => (int)($data[PromotionActivityTheme::schema_fields_IS_NAV_TAB] ?? 0),
            'price_band' => (string)($data[PromotionActivityTheme::schema_fields_PRICE_BAND] ?? ''),
            'product_pick_mode' => (string)($data[PromotionActivityTheme::schema_fields_PRODUCT_PICK_MODE] ?? PromotionThemeProductService::PICK_MODE_MANUAL),
            'product_filter_json' => (string)($data[PromotionActivityTheme::schema_fields_PRODUCT_FILTER_JSON] ?? ''),
            'deal_discount_type' => (string)($data[PromotionActivityTheme::schema_fields_DEAL_DISCOUNT_TYPE] ?? PromotionThemeDealDiscountSyncService::DISCOUNT_NONE),
            'deal_discount_value' => (float)($data[PromotionActivityTheme::schema_fields_DEAL_DISCOUNT_VALUE] ?? 0),
            'marketing_rule_id' => (int)($data[PromotionActivityTheme::schema_fields_MARKETING_RULE_ID] ?? 0),
            'storefront_url' => $this->storefrontUrl((string)($data[PromotionActivityTheme::schema_fields_PAGE_SLUG] ?? '')),
        ];
    }
}
