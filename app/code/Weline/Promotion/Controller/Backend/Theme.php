<?php

declare(strict_types=1);

namespace Weline\Promotion\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Promotion\Model\PromotionActivityTheme;
use Weline\Promotion\Service\PromotionActivityThemeService;
use Weline\Promotion\Service\PromotionScopeResolver;
use Weline\Promotion\Service\PromotionThemeFormDataService;
use Weline\Promotion\Service\PromotionThemeProductService;

#[Acl(
    'Weline_Promotion::commerce:promotion:theme',
    '活动主题',
    'mdi mdi-palette-outline',
    '管理前台活动主题、范围与多语言文案',
    'Weline_Backend::marketing_group',
)]
class Theme extends BackendController
{
    public function __construct(
        private readonly PromotionActivityThemeService $themeService,
        private readonly PromotionScopeResolver $scopeResolver,
        private readonly PromotionThemeProductService $themeProductService,
        private readonly PromotionThemeFormDataService $formDataService,
    ) {
    }

    #[Acl(
        'Weline_Promotion::commerce:promotion:theme_index',
        '查看活动主题',
        'mdi mdi-palette-outline',
        '查看并维护前台活动主题',
    )]
    public function index(): string
    {
        $scope = $this->scopeResolver->resolve();
        $websiteId = (int)$this->request->getGet('website_id', $scope['website_id'] ?? 0);
        $storeCode = trim((string)$this->request->getGet('store_code', $scope['store_code'] ?? ''));
        $channelCode = trim((string)$this->request->getGet('channel_code', $scope['channel_code'] ?? ''));

        $filters = [
            'website_id' => max(0, $websiteId),
            'store_code' => $storeCode,
            'channel_code' => $channelCode,
        ];
        $themes = [];
        foreach ($this->themeService->listForBackend($filters) as $theme) {
            $scopeSummary = $this->formDataService->describeScope(
                (int)($theme['website_id'] ?? 0),
                (string)($theme['store_code'] ?? ''),
                (string)($theme['channel_code'] ?? ''),
            );
            $themes[] = $theme + [
                'scope_label' => (string)$scopeSummary['label'],
                'scope_level' => (string)$scopeSummary['level'],
            ];
        }

        $formData = $this->formDataService->build($websiteId, $storeCode, $channelCode);
        foreach ($formData as $key => $value) {
            $this->assign($key, $value);
        }

        $this->assign('themes', $themes);
        $this->assign('scope', $scope);
        $this->assign('filter_website_id', max(0, $websiteId));
        $this->assign('filter_store_code', $storeCode);
        $this->assign('filter_channel_code', $channelCode);
        $this->assign('scope_filter_active', $websiteId > 0 || $storeCode !== '' || $channelCode !== '');

        return $this->fetch('Weline_Promotion::templates/backend/promotion/theme/index.phtml');
    }

    #[Acl(
        'Weline_Promotion::commerce:promotion:theme_form',
        '编辑活动主题',
        'mdi mdi-palette-outline',
        '创建或编辑活动主题',
    )]
    public function form(): string
    {
        $id = (int)$this->request->getGet('id', 0);
        $theme = null;
        if ($id > 0) {
            foreach ($this->themeService->listForBackend() as $item) {
                if ((int)($item['id'] ?? 0) === $id) {
                    $theme = $item;
                    break;
                }
            }
        }

        $scope = $this->scopeResolver->resolve();
        $websiteId = (int)$this->request->getGet(
            'website_id',
            is_array($theme) ? ($theme['website_id'] ?? 0) : ($scope['website_id'] ?? 0),
        );
        $storeCode = trim((string)$this->request->getGet(
            'store_code',
            is_array($theme) ? ($theme['store_code'] ?? '') : ($scope['store_code'] ?? ''),
        ));
        $channelCode = trim((string)$this->request->getGet(
            'channel_code',
            is_array($theme) ? ($theme['channel_code'] ?? '') : ($scope['channel_code'] ?? ''),
        ));

        $formData = $this->formDataService->build($websiteId, $storeCode, $channelCode);
        foreach ($formData as $key => $value) {
            $this->assign($key, $value);
        }

        // 勿用 assign('theme')：Theme 布局 Observer 会注入 layout theme 并覆盖同名变量。
        $this->assign('activityTheme', is_array($theme) ? $theme : $this->themeService->buildNewThemeFormDefaults());
        $this->assign('scope', $scope);
        $this->assign('form_website_id', $websiteId);
        $this->assign('form_store_code', $storeCode);
        $this->assign('form_channel_code', $channelCode);
        $this->assign('selected_products', is_array($theme) ? ($theme['selected_products'] ?? []) : []);
        $this->assign(
            'selectedProductsJson',
            json_encode(is_array($theme) ? ($theme['selected_products'] ?? []) : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        );
        $this->assign('promotionThemeProductSearchUrl', $this->getUrl('promotion/backend/theme/searchProducts'));
        $activityThemeData = is_array($theme) ? $theme : $this->themeService->buildNewThemeFormDefaults();
        $filterPreview = $this->buildFilterPreviewPayload(
            $activityThemeData,
            $websiteId,
            $storeCode,
            $channelCode,
        );
        $this->assign('filterPreviewJson', json_encode($filterPreview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        $this->assign('statuses', PromotionActivityTheme::allowedStatuses());
        $this->assign('pick_modes', PromotionThemeProductService::allowedPickModes());

        return $this->fetch('Weline_Promotion::templates/backend/promotion/theme/form.phtml');
    }

    #[Acl(
        'Weline_Promotion::commerce:promotion:theme_save',
        '保存活动主题',
        'mdi mdi-content-save-outline',
        '保存活动主题与 LocalModel 文案',
    )]
    public function postSave(): string
    {
        $params = $this->request->getParams();
        $accept = (string)($this->request->getHeader('Accept') ?? '');
        $wantsJson = str_contains(strtolower($accept), 'application/json')
            || (string)$this->request->getParam('ajax', '') === '1';
        $result = $this->themeService->saveTheme($params);
        if ($wantsJson) {
            return $this->fetchJson($result);
        }
        if (!empty($result['needs_overlap_confirm'])) {
            $this->getMessageManager()->addWarning((string)($result['message'] ?? __('存在交叉 SKU，请确认后保存。')));

            return $this->redirect('promotion/backend/theme/form', $params);
        }
        if (!($result['success'] ?? false)) {
            $this->getMessageManager()->addError((string)($result['message'] ?? __('保存失败。')));

            return $this->redirect('promotion/backend/theme/form', $params);
        }

        $this->getMessageManager()->addSuccess(
            (string)__('活动主题已保存。可点击各字段旁的「多语言」补全其它语言文案。')
        );

        return $this->redirect('promotion/backend/theme/index');
    }

    #[Acl(
        'Weline_Promotion::commerce:promotion:theme_search_products',
        '搜索活动商品',
        'mdi mdi-magnify',
        '按活动范围搜索可绑定商品',
    )]
    public function postSearchProducts(): string
    {
        return $this->fetchJson($this->themeProductService->searchProducts($this->request->getParams()));
    }

    #[Acl(
        'Weline_Promotion::commerce:promotion:theme_preview_filter',
        '预览条件选品',
        'mdi mdi-table-eye',
        '按筛选规则预览活动命中商品',
    )]
    public function postPreviewFilterProducts(): string
    {
        return $this->fetchJson($this->themeProductService->previewFilterProducts($this->request->getParams()));
    }

    /** @param array<string,mixed> $activityTheme @return array{success:bool,message?:string,items:list<array<string,mixed>>,total?:int,limit?:int} */
    private function buildFilterPreviewPayload(
        array $activityTheme,
        int $websiteId,
        string $storeCode,
        string $channelCode,
    ): array {
        if ((string)($activityTheme['product_pick_mode'] ?? '') !== PromotionThemeProductService::PICK_MODE_FILTER) {
            return ['success' => false, 'items' => [], 'message' => '', 'limit' => 12, 'total' => 0];
        }

        $filter = is_array($activityTheme['product_filter'] ?? null) ? $activityTheme['product_filter'] : [];

        return $this->themeProductService->previewFilterProducts([
            'website_id' => $websiteId,
            'store_code' => $storeCode,
            'channel_code' => $channelCode,
            'filter_product_type' => (string)($filter['product_type'] ?? ''),
            'filter_status' => (string)($filter['status'] ?? 'published'),
            'filter_name' => (string)($filter['name'] ?? ''),
            'filter_sku' => (string)($filter['sku'] ?? ''),
            'filter_product_code' => (string)($filter['product_code'] ?? ''),
            'filter_new_within_days' => (int)($filter['new_within_days'] ?? 0),
            'filter_limit' => (int)($filter['limit'] ?? 12),
            'page' => 1,
            'page_size' => 10,
        ]);
    }
}
