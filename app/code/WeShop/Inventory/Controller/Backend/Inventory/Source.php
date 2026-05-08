<?php

declare(strict_types=1);

namespace WeShop\Inventory\Controller\Backend\Inventory;

use WeShop\Inventory\Service\SourceAdminPageDataService;
use WeShop\Inventory\Service\SourceManagementService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Inventory::source', 'Sources', 'mdi mdi-warehouse', 'Manage inventory sources', 'WeShop_Inventory::inventory_management')]
class Source extends BaseController
{
    public function __construct(
        private readonly SourceManagementService $sourceManagementService,
        private readonly SourceAdminPageDataService $sourceAdminPageDataService
    ) {
    }

    #[Acl('WeShop_Inventory::source_index', 'View inventory sources', 'mdi mdi-warehouse', 'View inventory source management page')]
    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $sourceIndexUrl = (string) $this->request->getUrlBuilder()->getBackendUrl('*/backend/inventory/source');

        $this->assign(array_merge(
            [
                'title' => (string) __('Inventory Sources'),
                'sourceIndexUrl' => $sourceIndexUrl,
                'sourceAddUrl' => (string) $this->request->getUrlBuilder()->getBackendUrl('*/backend/inventory/source/add'),
            ],
            $this->sourceAdminPageDataService->getListData($page, $pageSize)
        ));

        return (string) $this->fetchBase('WeShop_Inventory::backend/templates/inventory/source/index.phtml');
    }

    #[Acl('WeShop_Inventory::source_add', 'Add inventory source', 'mdi mdi-plus-box-outline', 'Add inventory source data')]
    public function add(): string
    {
        $sourceIndexUrl = (string) $this->request->getUrlBuilder()->getBackendUrl('*/backend/inventory/source');

        if ($this->request->isPost()) {
            try {
                $source = $this->sourceManagementService->saveSource($this->collectSourcePayload());
                $this->getMessageManager()->addSuccess(__('Inventory source created.'));
                $this->redirect('*/backend/inventory/source/edit', ['id' => $source->getId()]);
                return '';
            } catch (\Throwable $throwable) {
                $this->getMessageManager()->addError($throwable->getMessage() ?: __('Unable to create inventory source.'));
                $this->assign('source', $this->collectSourcePayload());
            }
        } else {
            $this->assign('source', $this->sourceManagementService->getEmptySourceData());
        }

        $this->assign([
            'title' => (string) __('Create Inventory Source'),
            'action' => (string) $this->request->getUrlBuilder()->getCurrentUrl(),
            'sourceIndexUrl' => $sourceIndexUrl,
        ]);

        return (string) $this->fetchBase('WeShop_Inventory::backend/templates/inventory/source/form.phtml');
    }

    #[Acl('WeShop_Inventory::source_edit', 'Edit inventory source', 'mdi mdi-pencil-outline', 'Edit inventory source data')]
    public function edit(): string
    {
        $sourceId = (int) $this->request->getParam('id', 0);
        $sourceIndexUrl = (string) $this->request->getUrlBuilder()->getBackendUrl('*/backend/inventory/source');
        if ($sourceId <= 0) {
            $this->getMessageManager()->addError(__('Invalid source id.'));
            $this->redirect($sourceIndexUrl);
            return '';
        }

        if ($this->request->isPost()) {
            try {
                $this->sourceManagementService->saveSource($this->collectSourcePayload(), $sourceId);
                $this->getMessageManager()->addSuccess(__('Inventory source saved.'));
                $this->redirect('*/backend/inventory/source/edit', ['id' => $sourceId]);
                return '';
            } catch (\Throwable $throwable) {
                $this->getMessageManager()->addError($throwable->getMessage() ?: __('Unable to save inventory source.'));
                $this->assign('source', array_merge($this->collectSourcePayload(), ['source_id' => $sourceId]));
            }
        } else {
            try {
                $this->assign('source', $this->sourceAdminPageDataService->getEditData($sourceId));
            } catch (\Throwable $throwable) {
                $this->getMessageManager()->addError($throwable->getMessage() ?: __('Inventory source not found.'));
                $this->redirect($sourceIndexUrl);
                return '';
            }
        }

        $this->assign([
            'title' => (string) __('Edit Inventory Source'),
            'action' => (string) $this->request->getUrlBuilder()->getCurrentUrl(),
            'sourceIndexUrl' => $sourceIndexUrl,
        ]);

        return (string) $this->fetchBase('WeShop_Inventory::backend/templates/inventory/source/form.phtml');
    }

    #[Acl('WeShop_Inventory::source_delete_post', 'Delete inventory source', 'mdi mdi-delete-outline', 'Delete inventory source data')]
    public function postDelete(): string
    {
        return $this->deleteAction();
    }

    #[Acl('WeShop_Inventory::source_delete_get', 'Open inventory source delete route', 'mdi mdi-delete-outline', 'Open inventory source delete route')]
    public function getDelete(): string
    {
        // Keep GET compatibility for existing links while delegating to a single deletion flow.
        return $this->deleteAction();
    }

    private function deleteAction(): string
    {
        $sourceId = (int) $this->request->getParam('id', 0);
        $sourceIndexUrl = (string) $this->request->getUrlBuilder()->getBackendUrl('*/backend/inventory/source');

        try {
            $this->sourceManagementService->deleteSource($sourceId);
            $this->getMessageManager()->addSuccess(__('Inventory source deleted.'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Unable to delete inventory source.'));
        }

        $this->redirect($sourceIndexUrl);
        return '';
    }

    private function collectSourcePayload(): array
    {
        return [
            'code' => $this->request->getParam('code', ''),
            'name' => $this->request->getParam('name', ''),
            'description' => $this->request->getParam('description', ''),
            'country' => $this->request->getParam('country', ''),
            'region' => $this->request->getParam('region', ''),
            'city' => $this->request->getParam('city', ''),
            'address' => $this->request->getParam('address', ''),
            'postcode' => $this->request->getParam('postcode', ''),
            'phone' => $this->request->getParam('phone', ''),
            'email' => $this->request->getParam('email', ''),
            'contact_name' => $this->request->getParam('contact_name', ''),
            'is_enabled' => $this->request->getParam('is_enabled', 1),
            'priority' => $this->request->getParam('priority', 0),
            'use_default_carrier' => $this->request->getParam('use_default_carrier', 1),
        ];
    }
}

