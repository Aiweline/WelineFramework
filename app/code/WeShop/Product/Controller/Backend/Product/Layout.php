<?php

declare(strict_types=1);

namespace WeShop\Product\Controller\Backend\Product;

use WeShop\Catalog\Model\Category;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductLayout;
use WeShop\Product\Model\ProductLayoutSchedule;
use WeShop\Product\Service\ProductLayoutService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

#[Acl('WeShop_Product::product_layout', 'Product layout actions', 'mdi mdi-view-dashboard-edit-outline', 'Manage product layouts', 'WeShop_Product::product')]
class Layout extends BackendController
{
    private ProductLayoutService $layoutService;

    public function __construct(
        Request $request,
        ProductLayoutService $layoutService
    ) {
        $this->layoutService = $layoutService;
    }

    #[Acl('WeShop_Product::product_layout_index', 'View product layouts', 'mdi mdi-view-dashboard-outline', 'View product layout page')]
    public function index()
    {
        $context = $this->resolveLayoutContext();
        if ($context === null) {
            $this->getMessageManager()->addError(__('请选择产品或分类'));
            $this->redirect('*/backend/product');
            return '';
        }

        /** @var ProductLayout $layoutModel */
        $layoutModel = ObjectManager::getInstance(ProductLayout::class);
        $layouts = $layoutModel->getByEntity($context['entity_type'], $context['entity_id']);
        $schedules = $this->layoutService->getEntitySchedules(
            $context['entity_type'],
            $context['entity_id'],
            $context['layout_type']
        );

        $this->assign('layout_context', $context);
        $this->assign('currentLayoutContext', $this->resolveEffectiveLayoutContext($context));
        $this->assign('productLayouts', $layouts);
        $this->assign('schedules', $schedules);
        $this->assign('selectionVersions', $this->layoutService->listEntityLayoutSelectionVersions(
            $context['entity_type'],
            $context['entity_id'],
            $context['layout_type'],
            10
        ));
        $this->assign('layoutOptions', $this->layoutService->getAvailableEditableLayoutOptions(
            $context['editor_layout_type'],
            $this->buildVirtualLayoutIdentity($context)
        ));
        $this->assign('layoutTypes', $this->layoutTypeLabels());

        return $this->fetch();
    }

    #[Acl('WeShop_Product::product_layout_save', 'Save product layout', 'mdi mdi-content-save', 'Save product layout data')]
    public function save()
    {
        $context = $this->resolveLayoutContext();
        if ($context === null) {
            $this->getMessageManager()->addError(__('参数不完整'));
            $this->redirect('*/backend/product');
            return '';
        }

        $layoutCode = $this->layoutService->normalizeLayoutOptionCode((string)$this->request->getParam('layout_code'));
        if ($layoutCode === '' || !$this->layoutService->editableLayoutExists($context['editor_layout_type'], $layoutCode, $this->buildVirtualLayoutIdentity($context))) {
            $this->getMessageManager()->addError(__('布局选项无效'));
            $this->redirectToLayoutContext($context);
            return '';
        }

        $result = $this->layoutService->applyEntityLayout(
            $context['entity_type'],
            $context['entity_id'],
            $context['layout_type'],
            $layoutCode
        );

        if ($result) {
            $this->getMessageManager()->addSuccess(__('布局配置保存成功'));
        } else {
            $this->getMessageManager()->addError(__('布局配置保存失败'));
        }

        $this->redirectToLayoutContext($context);
        return '';
    }

    #[Acl('WeShop_Product::product_layout_selection_rollback', 'Rollback layout selection version', 'mdi mdi-history', 'Rollback product or category layout selection version')]
    public function rollbackSelectionVersion()
    {
        $context = $this->resolveLayoutContext();
        if ($context === null) {
            $this->getMessageManager()->addError(__('参数不完整'));
            $this->redirect('*/backend/product');
            return '';
        }

        $versionId = (int)$this->request->getParam('version_id');
        if ($versionId <= 0) {
            $this->getMessageManager()->addError(__('布局选择版本无效'));
            $this->redirectToLayoutContext($context);
            return '';
        }

        $result = $this->layoutService->rollbackEntityLayoutSelectionVersion(
            $context['entity_type'],
            $context['entity_id'],
            $context['layout_type'],
            $versionId,
            (string)__('后台回滚布局选择版本')
        );

        if (!empty($result['success'])) {
            $this->getMessageManager()->addSuccess(__('布局选择版本已回滚'));
        } else {
            $this->getMessageManager()->addError($this->formatSelectionRollbackError($result));
        }

        $this->redirectToLayoutContext($context);
        return '';
    }

    #[Acl('WeShop_Product::product_layout_delete', 'Delete product layout', 'mdi mdi-delete-outline', 'Delete product layout data')]
    public function delete()
    {
        $context = $this->resolveLayoutContext();
        $layoutId = (int)$this->request->getParam('layout_id');
        if ($context === null) {
            $this->getMessageManager()->addError(__('请选择要删除的布局'));
            $this->redirect('*/backend/product');
            return '';
        }

        if ($layoutId <= 0) {
            $deleted = $this->layoutService->deleteEntityLayoutSelection(
                $context['entity_type'],
                $context['entity_id'],
                $context['layout_type']
            );
            $deleted
                ? $this->getMessageManager()->addSuccess(__('布局配置已删除'))
                : $this->getMessageManager()->addError(__('布局配置删除失败'));
            $this->redirectToLayoutContext($context);
            return '';
        }

        /** @var ProductLayout $layoutModel */
        $layoutModel = ObjectManager::getInstance(ProductLayout::class);
        $layoutModel->load($layoutId);

        if ($layoutModel->getId()
            && $layoutModel->getEntityType() === $context['entity_type']
            && $layoutModel->getEntityId() === $context['entity_id']
        ) {
            $layoutModel->setIsActive(false)->save();
            $this->getMessageManager()->addSuccess(__('布局配置已删除'));
        } else {
            $this->getMessageManager()->addError(__('布局配置不存在'));
        }

        $this->redirectToLayoutContext($context);
        return '';
    }

    #[Acl('WeShop_Product::product_layout_editor', 'Edit layout source', 'mdi mdi-file-code-outline', 'Edit product or category layout source')]
    public function editLayout()
    {
        $context = $this->resolveLayoutContext();
        $layoutType = (string)$this->request->getParam('layout_type', $context['editor_layout_type'] ?? ProductLayout::LAYOUT_TYPE_PRODUCT);
        $layoutType = $this->layoutService->normalizeEditableLayoutType($layoutType);
        $layoutCode = $this->layoutService->normalizeLayoutOptionCode((string)$this->request->getParam('layout_code'));
        $identity = $this->buildVirtualLayoutIdentity($context ?? []);
        $source = $layoutCode !== '' ? $this->layoutService->loadEditableLayoutSource($layoutType, $layoutCode, $identity) : null;

        $this->assign('layout_context', $context);
        $this->assign('layout_type', $layoutType);
        $this->assign('layout_code', $layoutCode);
        $this->assign('layout_source', $source ?? $this->layoutService->getDefaultEditableLayoutSource($layoutType));
        $this->assign('layout_versions', $layoutCode !== ''
            ? $this->layoutService->listEditableLayoutVersions($layoutType, $layoutCode, $identity)
            : []
        );

        return $this->fetch();
    }

    #[Acl('WeShop_Product::product_layout_source_save', 'Save layout source', 'mdi mdi-content-save-edit', 'Save editable layout source')]
    public function saveLayout()
    {
        $context = $this->resolveLayoutContext();
        $layoutType = (string)$this->request->getParam('layout_type', $context['editor_layout_type'] ?? ProductLayout::LAYOUT_TYPE_PRODUCT);
        $layoutCode = (string)$this->request->getParam('layout_code');
        $layoutSource = (string)$this->request->getParam('layout_source');

        if ((string)$this->request->getParam('layout_action', '') === 'rollback') {
            return $this->rollbackLayoutVersionFromRequest($context ?? [], $layoutType, $layoutCode);
        }

        $error = null;
        $saved = $this->layoutService->saveEditableLayoutSource($layoutType, $layoutCode, $layoutSource, $error, $this->buildVirtualLayoutIdentity($context ?? []));
        if ($saved) {
            $this->getMessageManager()->addSuccess(__('布局模板保存成功'));
        } else {
            $this->getMessageManager()->addError($error ? __($error) : __('布局模板保存失败'));
        }

        if ($context !== null) {
            $this->redirectToLayoutEditContext($context, $layoutType, $layoutCode);
            return '';
        }

        $this->redirect('*/backend/product/layout/edit-layout', [
            'layout_type' => $this->layoutService->normalizeEditableLayoutType($layoutType),
            'layout_code' => $this->layoutService->normalizeLayoutOptionCode($layoutCode),
        ]);
        return '';
    }

    private function rollbackLayoutVersionFromRequest(array $context, string $layoutType, string $layoutCode): string
    {
        if ($context === []) {
            $this->getMessageManager()->addError(__('参数不完整'));
            $this->redirect('*/backend/product');
            return '';
        }

        $assetId = (int)$this->request->getParam('asset_id');
        $versionId = (int)$this->request->getParam('version_id');
        $error = null;

        $rolledBack = $this->layoutService->rollbackEditableLayoutVersion(
            $layoutType,
            $layoutCode,
            $assetId,
            $versionId,
            $error,
            $this->buildVirtualLayoutIdentity($context)
        );

        $rolledBack
            ? $this->getMessageManager()->addSuccess(__('布局版本已回滚'))
            : $this->getMessageManager()->addError($error ? __($error) : __('布局版本回滚失败'));

        $this->redirectToLayoutEditContext($context, $layoutType, $layoutCode);
        return '';
    }

    #[Acl('WeShop_Product::product_layout_preview', 'Preview layout', 'mdi mdi-eye-outline', 'Preview product or category layout')]
    public function previewLayout()
    {
        $context = $this->resolveLayoutContext();
        if ($context === null) {
            $this->getMessageManager()->addError(__('预览需要产品或分类上下文'));
            $this->redirect('*/backend/product');
            return '';
        }

        $layoutCode = $this->layoutService->normalizeLayoutOptionCode((string)$this->request->getParam('layout_code'));
        if ($layoutCode === '' || !$this->layoutService->editableLayoutExists($context['editor_layout_type'], $layoutCode, $this->buildVirtualLayoutIdentity($context))) {
            $this->getMessageManager()->addError(__('布局选项无效'));
            $this->redirectToLayoutContext($context);
            return '';
        }

        if ($context['entity_type'] === ProductLayout::ENTITY_CATEGORY) {
            $this->redirect('/catalog/category/view', [
                'id' => $context['entity_id'],
                'layout_preview' => 1,
                'layout_option' => $layoutCode,
                'no_cache' => 1,
            ]);
            return '';
        }

        if ($context['entity_type'] === ProductLayout::ENTITY_PRODUCT) {
            $this->redirect('/product/frontend/product/view', [
                'id' => $context['entity_id'],
                'layout_preview' => 1,
                'layout_option' => $layoutCode,
                'no_cache' => 1,
            ]);
            return '';
        }

        $previewProductId = (int)$this->request->getParam('preview_product_id');
        if ($previewProductId <= 0) {
            $this->getMessageManager()->addError(__('分类下商品默认布局预览需要提供产品 ID'));
            $this->redirectToLayoutContext($context);
            return '';
        }

        $this->redirect('/product/frontend/product/view', [
            'id' => $previewProductId,
            'layout_preview' => 1,
            'layout_option' => $layoutCode,
            'theme_layout_source_target_type' => ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT,
            'theme_layout_source_target_id' => $context['entity_id'],
            'no_cache' => 1,
        ]);
        return '';
    }

    #[Acl('WeShop_Product::product_layout_schedule_create', 'Create layout schedule', 'mdi mdi-calendar-plus', 'Create product layout schedule')]
    public function createSchedule()
    {
        $context = $this->resolveLayoutContext();
        if ($context === null) {
            $this->getMessageManager()->addError(__('参数不完整'));
            $this->redirect('*/backend/product');
            return '';
        }

        $layoutCode = $this->layoutService->normalizeLayoutOptionCode((string)$this->request->getParam('layout_code'));
        $startTime = trim((string)$this->request->getParam('start_time'));
        if ($layoutCode === '' || $startTime === '' || !$this->layoutService->editableLayoutExists($context['editor_layout_type'], $layoutCode, $this->buildVirtualLayoutIdentity($context))) {
            $this->getMessageManager()->addError(__('请填写完整计划信息'));
            $this->redirectToLayoutContext($context);
            return '';
        }

        $schedule = $this->layoutService->createEntityLayoutSchedule(
            $context['entity_type'],
            $context['entity_id'],
            $context['layout_type'],
            $layoutCode,
            $startTime,
            $this->request->getParam('end_time') ?: null,
            (bool)$this->request->getParam('is_recurring'),
            (string)($this->request->getParam('cron_expression') ?: ''),
            (string)($this->request->getParam('description') ?: ''),
            (int)$this->request->getParam('priority', 0),
            (string)($this->request->getParam('timezone') ?: '')
        );

        if ($schedule) {
            $this->getMessageManager()->addSuccess(__('布局计划创建成功'));
        } else {
            $this->getMessageManager()->addError(__('布局计划创建失败'));
        }

        $this->redirectToLayoutContext($context);
        return '';
    }

    #[Acl('WeShop_Product::product_layout_schedule_edit', 'Edit layout schedule', 'mdi mdi-calendar-edit', 'Edit product layout schedule')]
    public function editSchedule()
    {
        $schedule = $this->loadScheduleFromRequest();
        if (!$schedule) {
            $this->getMessageManager()->addError(__('计划不存在'));
            $this->redirect('*/backend/product');
            return '';
        }

        $context = $this->resolveLayoutContext($schedule);
        $data = [
            'layout_code' => $this->layoutService->normalizeLayoutOptionCode((string)$this->request->getParam('layout_code', $schedule->getLayoutCode())),
            'start_time' => $this->request->getParam('start_time', $schedule->getStartTime()),
            'end_time' => $this->request->getParam('end_time') ?: null,
            'is_recurring' => (bool)$this->request->getParam('is_recurring'),
            'cron_expression' => (string)($this->request->getParam('cron_expression') ?: ''),
            'status' => (string)$this->request->getParam('status', $schedule->getStatus()),
            'description' => (string)($this->request->getParam('description') ?: ''),
            'priority' => (int)$this->request->getParam('priority', $schedule->getPriority()),
            'timezone' => (string)$this->request->getParam('timezone', $schedule->getTimezone()),
        ];

        $result = $this->layoutService->updateProductLayoutSchedule((int)$schedule->getId(), $data);
        $result
            ? $this->getMessageManager()->addSuccess(__('布局计划更新成功'))
            : $this->getMessageManager()->addError(__('布局计划更新失败'));

        $this->redirectToLayoutContext($context);
        return '';
    }

    #[Acl('WeShop_Product::product_layout_schedule_delete', 'Delete layout schedule', 'mdi mdi-calendar-remove', 'Delete product layout schedule')]
    public function deleteSchedule()
    {
        $schedule = $this->loadScheduleFromRequest();
        if (!$schedule) {
            $this->getMessageManager()->addError(__('计划不存在'));
            $this->redirect('*/backend/product');
            return '';
        }

        $context = $this->resolveLayoutContext($schedule);
        $result = $this->layoutService->deleteProductLayoutSchedule((int)$schedule->getId());
        $result
            ? $this->getMessageManager()->addSuccess(__('布局计划已删除'))
            : $this->getMessageManager()->addError(__('布局计划删除失败'));

        $this->redirectToLayoutContext($context);
        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveLayoutContext(?ProductLayoutSchedule $schedule = null): ?array
    {
        $entityType = $schedule?->getEntityType() ?: (string)$this->request->getParam('entity_type', '');
        $entityType = trim($entityType);
        if ($entityType === '') {
            $entityType = ((int)$this->request->getParam('category_id') > 0)
                ? ProductLayout::ENTITY_CATEGORY
                : ProductLayout::ENTITY_PRODUCT;
        }

        if (!in_array($entityType, [
            ProductLayout::ENTITY_PRODUCT,
            ProductLayout::ENTITY_CATEGORY,
            ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT,
        ], true)) {
            return null;
        }

        if ($schedule) {
            $entityId = $schedule->getEntityId();
        } elseif ($entityType === ProductLayout::ENTITY_PRODUCT) {
            $entityId = (int)($this->request->getParam('product_id') ?: $this->request->getParam('entity_id') ?: $this->request->getParam('id'));
        } else {
            $entityId = (int)($this->request->getParam('category_id') ?: $this->request->getParam('entity_id') ?: $this->request->getParam('id'));
        }
        if ($entityId <= 0) {
            return null;
        }

        $entityLabel = '';
        $entity = null;
        if ($entityType === ProductLayout::ENTITY_PRODUCT) {
            /** @var Product $product */
            $product = ObjectManager::getInstance(Product::class);
            $product->load($entityId);
            if (!$product->getId()) {
                return null;
            }
            $entity = $product;
            $entityLabel = (string)($product->getData(Product::schema_fields_name) ?: ('#' . $entityId));
            $layoutType = ProductLayout::LAYOUT_TYPE_PRODUCT;
            $editorLayoutType = ProductLayout::LAYOUT_TYPE_PRODUCT;
            $redirectParams = ['product_id' => $entityId, 'entity_type' => ProductLayout::ENTITY_PRODUCT];
        } else {
            /** @var Category $category */
            $category = ObjectManager::getInstance(Category::class);
            $category->load($entityId);
            if (!$category->getId()) {
                return null;
            }
            $entity = $category;
            $entityLabel = (string)($category->getData(Category::schema_fields_NAME) ?: ('#' . $entityId));
            $layoutType = $entityType === ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT
                ? ProductLayout::LAYOUT_TYPE_CATEGORY_PRODUCT_DEFAULT
                : ProductLayout::LAYOUT_TYPE_CATEGORY;
            $editorLayoutType = $entityType === ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT
                ? ProductLayout::LAYOUT_TYPE_PRODUCT
                : ProductLayout::LAYOUT_TYPE_CATEGORY;
            $redirectParams = ['category_id' => $entityId, 'entity_type' => $entityType];
        }

        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity' => $entity,
            'entity_label' => $entityLabel,
            'layout_type' => $layoutType,
            'editor_layout_type' => $editorLayoutType,
            'title' => $this->layoutTypeLabels()[$entityType] ?? __('布局管理'),
            'redirect_params' => $redirectParams,
        ];
    }

    private function redirectToLayoutContext(array $context): void
    {
        $this->redirect('*/backend/product/layout', $context['redirect_params'] ?? []);
    }

    private function redirectToLayoutEditContext(array $context, string $layoutType, string $layoutCode): void
    {
        $this->redirect('*/backend/product/layout/edit-layout', array_merge(
            $context['redirect_params'] ?? [],
            [
                'layout_type' => $this->layoutService->normalizeEditableLayoutType($layoutType),
                'layout_code' => $this->layoutService->normalizeLayoutOptionCode($layoutCode),
            ]
        ));
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildVirtualLayoutIdentity(array $context): array
    {
        return [
            'area' => 'frontend',
            'layout_type' => (string)($context['editor_layout_type'] ?? ProductLayout::LAYOUT_TYPE_PRODUCT),
            'target_type' => (string)($context['entity_type'] ?? ProductLayout::ENTITY_PRODUCT),
            'target_id' => (int)($context['entity_id'] ?? 0),
            'name' => (string)($context['entity_label'] ?? ''),
            'metadata' => [
                'weshop_layout_type' => (string)($context['layout_type'] ?? ''),
                'weshop_entity_type' => (string)($context['entity_type'] ?? ''),
                'weshop_entity_id' => (int)($context['entity_id'] ?? 0),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function resolveEffectiveLayoutContext(array $context): ?array
    {
        $entityType = (string)($context['entity_type'] ?? '');
        $entityId = (int)($context['entity_id'] ?? 0);
        if ($entityId <= 0) {
            return null;
        }

        try {
            if ($entityType === ProductLayout::ENTITY_PRODUCT) {
                return $this->layoutService->resolveProductLayoutContext($entityId, ProductLayout::LAYOUT_TYPE_PRODUCT);
            }
            if ($entityType === ProductLayout::ENTITY_CATEGORY) {
                return $this->layoutService->resolveCategoryLayoutContext($entityId, ProductLayout::LAYOUT_TYPE_CATEGORY);
            }
            if ($entityType === ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT) {
                $layoutCode = $this->layoutService->resolveCategoryProductDefaultLayoutOption($entityId);
                return trim((string)$layoutCode) !== '' ? [
                    'layout_code' => $layoutCode,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'layout_type' => ProductLayout::LAYOUT_TYPE_CATEGORY_PRODUCT_DEFAULT,
                    'source' => ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT,
                ] : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function loadScheduleFromRequest(): ?ProductLayoutSchedule
    {
        $scheduleId = (int)$this->request->getParam('schedule_id');
        if ($scheduleId <= 0) {
            return null;
        }

        /** @var ProductLayoutSchedule $schedule */
        $schedule = ObjectManager::getInstance(ProductLayoutSchedule::class);
        $schedule->load($scheduleId);
        return $schedule->getId() ? $schedule : null;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function formatSelectionRollbackError(array $result): string
    {
        $precheck = is_array($result['precheck'] ?? null) ? $result['precheck'] : [];
        $blockers = is_array($precheck['blockers'] ?? null) ? $precheck['blockers'] : [];
        $conflicts = is_array($precheck['conflicts'] ?? null) ? $precheck['conflicts'] : [];
        $status = (string)($result['status'] ?? $precheck['status'] ?? 'failed');

        $reasons = $blockers;
        foreach ($conflicts as $conflict) {
            if (!is_array($conflict)) {
                continue;
            }
            $reason = (string)($conflict['reason'] ?? '');
            if ($reason !== '') {
                $reasons[] = $reason;
            }
        }

        $reasonText = implode(', ', array_values(array_unique(array_filter(array_map('strval', $reasons)))));
        if ($reasonText !== '') {
            return (string)__('布局选择版本回滚失败：%{1}', $reasonText);
        }

        return (string)__('布局选择版本回滚失败：%{1}', $status);
    }

    /**
     * @return array<string, string>
     */
    private function layoutTypeLabels(): array
    {
        return [
            ProductLayout::ENTITY_PRODUCT => (string)__('产品专属布局'),
            ProductLayout::ENTITY_CATEGORY => (string)__('分类展示布局'),
            ProductLayout::ENTITY_CATEGORY_PRODUCT_DEFAULT => (string)__('分类下商品默认布局'),
        ];
    }
}
