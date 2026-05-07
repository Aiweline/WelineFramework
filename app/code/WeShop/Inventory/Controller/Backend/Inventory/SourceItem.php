<?php

declare(strict_types=1);

namespace WeShop\Inventory\Controller\Backend\Inventory;

use WeShop\Inventory\Service\SourceItemAdminPageDataService;
use WeShop\Inventory\Service\SourceItemManagementService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Inventory::source_item', 'Source Items', 'mdi mdi-package-variant-closed', 'Manage source inventory items', 'WeShop_Inventory::inventory_management')]
class SourceItem extends BaseController
{
    public function __construct(
        private readonly SourceItemManagementService $sourceItemManagementService,
        private readonly SourceItemAdminPageDataService $sourceItemAdminPageDataService
    ) {
    }

    #[Acl('WeShop_Inventory::source_item_index', 'View source items', 'mdi mdi-package-search-outline', 'View source item management page')]
    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $filters = [
            'source_id' => $this->request->getParam('source_id', 0),
            'search' => $this->request->getParam('search', ''),
        ];
        $sourceItemIndexUrl = (string) $this->request->getUrlBuilder()->getBackendUrl('*/backend/inventory/source-item');

        $this->assign(array_merge(
            [
                'title' => (string) __('Inventory Source Items'),
                'sourceItemIndexUrl' => $sourceItemIndexUrl,
            ],
            $this->sourceItemAdminPageDataService->getListData($page, $pageSize, $filters)
        ));

        return (string) $this->fetchBase('WeShop_Inventory::backend/templates/inventory/source-item/index.phtml');
    }

    #[Acl('WeShop_Inventory::source_item_edit', 'Edit source item', 'mdi mdi-package-variant-closed-edit', 'Edit source item inventory data')]
    public function edit(): string
    {
        $sourceItemId = (int) $this->request->getParam('id', 0);
        $sourceItemIndexUrl = (string) $this->request->getUrlBuilder()->getBackendUrl('*/backend/inventory/source-item');
        if ($sourceItemId <= 0) {
            $this->getMessageManager()->addError(__('Invalid source item id.'));
            $this->redirect($sourceItemIndexUrl);
            return '';
        }

        if ($this->request->isPost()) {
            try {
                $this->sourceItemManagementService->updateSourceItem($sourceItemId, [
                    // 必须用 double 过滤：默认 0 时 gettype 为 integer，会把 "25.25" 截断成 25
                    'quantity' => $this->request->getParam('quantity', 0.0, 'double'),
                    'low_stock_threshold' => $this->request->getParam('low_stock_threshold', 0),
                ]);
                $this->getMessageManager()->addSuccess(__('Inventory stock updated.'));
                $this->redirect('*/backend/inventory/source-item/edit', ['id' => $sourceItemId]);
                return '';
            } catch (\Throwable $throwable) {
                $this->getMessageManager()->addError($throwable->getMessage() ?: __('Unable to update inventory stock.'));
            }
        }

        try {
            $this->assign(array_merge(
                [
                    'title' => (string) __('Edit Inventory Source Item'),
                    'action' => (string) $this->request->getUrlBuilder()->getCurrentUrl(),
                    'sourceItemIndexUrl' => $sourceItemIndexUrl,
                    'statusOptions' => $this->sourceItemManagementService->getStatusOptions(),
                ],
                $this->sourceItemAdminPageDataService->getEditData($sourceItemId)
            ));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Inventory source item not found.'));
            $this->redirect($sourceItemIndexUrl);
            return '';
        }

        return (string) $this->fetchBase('WeShop_Inventory::backend/templates/inventory/source-item/form.phtml');
    }

    #[Acl('WeShop_Inventory::source_item_batch_adjust', 'Batch adjust source items', 'mdi mdi-playlist-edit', 'Batch adjust source item inventory data')]
    public function postBatchAdjust(): string
    {
        try {
            $adjusted = $this->sourceItemManagementService->batchAdjust(
                (array) $this->request->getPost('adjustments', [])
            );

            return $this->fetchJson([
                'success' => true,
                'adjusted_count' => $adjusted,
                'message' => __('Inventory batch adjustment completed.'),
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage() ?: __('Inventory batch adjustment failed.'),
            ]);
        }
    }

    #[Acl('WeShop_Inventory::source_item_product_stock', 'View product stock', 'mdi mdi-package-search', 'View product source stock data')]
    public function getProductStock(): string
    {
        $productId = (int) $this->request->getParam('product_id', 0);
        if ($productId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Invalid product id.'),
            ]);
        }

        $summary = $this->sourceItemManagementService->getProductStockSummary($productId);

        return $this->fetchJson(array_merge(['success' => true], $summary));
    }
}

