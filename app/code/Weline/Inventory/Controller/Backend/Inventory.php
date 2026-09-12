<?php

declare(strict_types=1);

namespace Weline\Inventory\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Service\InventoryAdminMutationService;
use Weline\Inventory\Service\InventoryAdminViewService;
use Weline\Inventory\Service\WarehouseCodeLabelAdminService;
use Weline\Websites\Service\StoreSelectOptions;

final class Inventory extends BackendController
{
    private const TITLES = [
        'stocks' => '库存',
        'adjustments' => '库存调整',
        'warehouses' => '仓库',
        'authorizations' => '仓库授权',
        'reservations' => '库存预占',
        'leases' => '预占租约',
        'ledger' => '库存账本',
        'migration' => '库存迁移',
    ];

    public function __construct(
        private readonly InventoryAdminViewService $adminView,
        private readonly InventoryAdminMutationService $mutations,
        private readonly WarehouseCodeLabelAdminService $codeLabels,
    ) {
    }

    #[Acl('Weline_Inventory::commerce:inventory:stocks', '库存', 'circle', '查看库存投影')]
    public function stocks(): string { return $this->renderSection('stocks'); }

    #[Acl('Weline_Inventory::commerce:inventory:adjustments', '库存调整', 'settings', '查看库存调整事件')]
    public function adjustments(): string { return $this->renderSection('adjustments'); }

    #[Acl('Weline_Inventory::commerce:inventory:warehouses', '仓库', 'home', '查看仓库')]
    public function warehouses(): string { return $this->renderSection('warehouses'); }

    #[Acl('Weline_Inventory::commerce:inventory:authorizations', '仓库授权', 'user', '查看仓库授权')]
    public function authorizations(): string { return $this->renderSection('authorizations'); }

    #[Acl('Weline_Inventory::commerce:inventory:reservations', '库存预占', 'clock', '查看库存预占')]
    public function reservations(): string { return $this->renderSection('reservations'); }

    #[Acl('Weline_Inventory::commerce:inventory:leases', '预占租约', 'clock', '查看预占租约')]
    public function leases(): string { return $this->renderSection('leases'); }

    #[Acl('Weline_Inventory::commerce:inventory:ledger', '库存账本', 'book', '查看库存不可变账本')]
    public function ledger(): string { return $this->renderSection('ledger'); }

    #[Acl('Weline_Inventory::commerce:inventory:migration', '库存迁移', 'refresh', '查看库存迁移状态')]
    public function migration(): string { return $this->renderSection('migration'); }

    #[Acl('Weline_Inventory::commerce:inventory:warehouses', '创建仓库', 'home', '创建仓库')]
    public function postCreateWarehouse(): string
    {
        return $this->handleMutation('warehouses', function (int $websiteId): void {
            $this->mutations->createWarehouse(
                $websiteId,
                $this->postString('warehouse_code', 64),
                $this->postString('name', 128),
                $this->postString('mode', 16),
                $this->postString('warehouse_type', 16),
            );
        }, '仓库已创建');
    }

    #[Acl('Weline_Inventory::commerce:inventory:warehouses', '树内创建仓库', 'home', '在国家/省份节点下快速创建仓库')]
    public function postCreateChildWarehouse(): string
    {
        return $this->handleMutation('warehouses', function (int $websiteId): void {
            $country = strtoupper(trim((string)$this->request->getPost('country_code', '')));
            $region = strtoupper(trim((string)$this->request->getPost('region_code', '')));
            $this->mutations->createChildWarehouse(
                $websiteId,
                $this->postNonNegativeInt('parent_id', 0),
                $this->postString('node_kind', 16),
                $this->postString('code_segment', 32),
                $this->postString('name', 128),
                $this->postString('mode', 16),
                $this->postString('warehouse_type', 16),
                $country !== '' ? $country : null,
                $region !== '' ? $region : null,
            );
        }, '仓库节点已创建');
    }

    #[Acl('Weline_Inventory::commerce:inventory:warehouses', '初始化默认仓库树', 'home', '按全球电商履约国种子仓库树')]
    public function postSeedWarehouses(): string
    {
        return $this->handleMutation('warehouses', function (int $websiteId): void {
            $mode = trim((string)$this->request->getPost('mode', Warehouse::MODE_NORMAL));
            $result = $this->mutations->seedDefaultWarehouses($websiteId, $mode !== '' ? $mode : Warehouse::MODE_NORMAL);
            $this->getMessageManager()->addSuccess(__(
                '默认仓库树已同步（新建 %{1}，跳过 %{2}）',
                [(string)$result['created'], (string)$result['skipped']],
            ));
        }, '');
    }

    #[Acl('Weline_Inventory::commerce:inventory:warehouses', '删除仓库', 'home', '删除无库存仓库')]
    public function postDeleteWarehouse(): string
    {
        return $this->handleMutation('warehouses', function (int $websiteId): void {
            $this->mutations->deleteWarehouse(
                $websiteId,
                $this->postPositiveInt('warehouse_id'),
            );
        }, '仓库已删除');
    }

    #[Acl('Weline_Inventory::commerce:inventory:warehouses', '保存仓码别名', 'home', '保存层级/模式/类型码别名')]
    public function postSaveWarehouseCodeLabel(): string
    {
        return $this->handleMutation('warehouses', function (): void {
            $sortRaw = trim((string)$this->request->getPost('sort_order', ''));
            $this->mutations->saveWarehouseCodeLabel(
                $this->postString('code_group', 32),
                $this->postString('code', 32),
                $this->postString('label_name', 128),
                $sortRaw !== '' ? max(0, (int)$sortRaw) : null,
            );
        }, '码别名已保存', 'labels');
    }

    #[Acl('Weline_Inventory::commerce:inventory:warehouses', '删除仓码别名', 'home', '删除自建仓码别名')]
    public function postDeleteWarehouseCodeLabel(): string
    {
        return $this->handleMutation('warehouses', function (): void {
            $this->mutations->deleteWarehouseCodeLabel($this->postPositiveInt('label_id'));
        }, '码别名已删除', 'labels');
    }

    #[Acl('Weline_Inventory::commerce:inventory:warehouses', '同步仓码别名种子', 'home', '同步默认层级/模式/类型码别名')]
    public function postSeedWarehouseCodeLabels(): string
    {
        return $this->handleMutation('warehouses', function (): void {
            $n = $this->mutations->seedWarehouseCodeLabels();
            $this->getMessageManager()->addSuccess(__('码别名种子已同步（%{1}）', [(string)$n]));
        }, '', 'labels');
    }

    #[Acl('Weline_Inventory::commerce:inventory:authorizations', '创建仓库授权', 'user', '创建仓库授权')]
    public function postAuthorizeWarehouse(): string
    {
        return $this->handleMutation('authorizations', function (int $websiteId): void {
            $this->mutations->authorizeWarehouse(
                $websiteId,
                $this->postNonNegativeInt('store_id', 0),
                $this->postPositiveInt('warehouse_id'),
                (string)$this->request->getPost('is_default', '0') === '1',
            );
        }, '仓库授权已创建');
    }

    #[Acl('Weline_Inventory::commerce:inventory:authorizations', '删除仓库授权', 'user', '删除仓库授权')]
    public function postDeleteAuthorization(): string
    {
        return $this->handleMutation('authorizations', function (int $websiteId): void {
            $this->mutations->deleteAuthorization(
                $websiteId,
                $this->postPositiveInt('authorization_id'),
            );
        }, '仓库授权已删除');
    }

    #[Acl('Weline_Inventory::commerce:inventory:authorizations', '同步默认站店授权种子', 'user', '同步默认站店授权种子')]
    public function postSeedDefaultAuthorization(): string
    {
        return $this->handleMutation('authorizations', function (): void {
            $result = $this->mutations->ensureDefaultStoreWarehouseMount();
            $this->getMessageManager()->addSuccess(
                __('默认站店授权已同步（挂载 %{1} 条种子授权）', [(string)$result['mounted']]),
            );
        }, '');
    }

    #[Acl('Weline_Inventory::commerce:inventory:adjustments', '提交库存调整', 'settings', '设置商品可用库存')]
    public function postAdjustStock(): string
    {
        return $this->handleMutation('adjustments', function (int $websiteId): void {
            $this->mutations->setOnHand(
                $websiteId,
                $this->postNonNegativeInt('store_id', 0),
                $this->postPositiveInt('offer_id'),
                $this->postNonNegativeInt('on_hand_minor', 0),
                $this->postString('command_id', 96),
                $this->postString('strategy', 16),
            );
        }, '库存调整已提交');
    }

    private function renderSection(string $section): string
    {
        $websiteId = max(0, (int)$this->request->getGet('website_id', 0));
        $storeId = max(0, (int)$this->request->getGet('store_id', 0));
        $keyword = trim((string)$this->request->getGet('keyword', ''));
        $page = max(1, (int)$this->request->getGet('page', 1));
        $limit = (int)$this->request->getGet('limit', 10);
        $limit = $limit > 0 ? min($limit, 50) : 10;
        $rows = [];
        $columns = [];
        $columnLabels = [];
        $authMeta = [
            'keyword' => $keyword,
            'page' => $page,
            'limit' => $limit,
            'total' => 0,
            'total_pages' => 1,
        ];
        $error = '';
        try {
            $options = $section === 'authorizations'
                ? ['keyword' => $keyword, 'page' => $page, 'limit' => $limit]
                : [];
            $result = $this->adminView->load($section, $websiteId, $storeId, $options);
            $rows = $result['rows'];
            $columns = $result['columns'];
            $columnLabels = is_array($result['column_labels'] ?? null) ? $result['column_labels'] : [];
            if (is_array($result['meta'] ?? null)) {
                $authMeta = array_merge($authMeta, $result['meta']);
            }
        } catch (\Throwable) {
            $this->request->getResponse()->setCode(503);
            $error = (string)__('数据读取失败，请检查库存模块状态与数据库连接');
        }
        $this->assign('title', __(self::TITLES[$section]));
        $this->assign('section', $section);
        $this->assign('website_id', $websiteId);
        $this->assign('store_id', $storeId);
        $this->assign('auth_keyword', (string)($authMeta['keyword'] ?? $keyword));
        $this->assign('auth_page', (int)($authMeta['page'] ?? $page));
        $this->assign('auth_limit', (int)($authMeta['limit'] ?? $limit));
        $this->assign('auth_total', (int)($authMeta['total'] ?? 0));
        $this->assign('auth_total_pages', (int)($authMeta['total_pages'] ?? 1));
        $this->assign('rows', $rows);
        $this->assign('columns', $columns);
        $this->assign('column_labels', $columnLabels);
        $this->assign('error', $error);
        $codeLabelRows = [];
        if ($section === 'warehouses') {
            try {
                $this->codeLabels->seedDefaults();
                $codeLabelRows = $this->codeLabels->listAll();
            } catch (\Throwable) {
                $codeLabelRows = [];
            }
        }
        $this->assign('code_label_rows', $codeLabelRows);
        $this->assignWebsiteSelect((string)$websiteId);
        $this->assignStoreSelect($websiteId, $storeId);
        return (string)$this->fetch('index');
    }

    private function assignWebsiteSelect(string $selectedValue): void
    {
        $options = [];
        try {
            $queried = \w_query('websites', 'getWebsiteSelectOptions', [], 'backend');
            if (\is_array($queried)) {
                $options = $queried;
            }
        } catch (\Throwable) {
            $options = [];
        }
        $display = '';
        foreach ($options as $option) {
            if (!\is_array($option)) {
                continue;
            }
            if ((string)($option['value'] ?? '') === $selectedValue) {
                $display = \trim((string)($option['label'] ?? ''));
                break;
            }
        }
        if ($display === '' && $selectedValue !== '') {
            $display = '#' . $selectedValue;
        }
        $this->assign('websiteSelectValue', $selectedValue);
        $this->assign('websiteSelectDisplay', $display);
        $this->assign('websiteSelectOptionsJson', \json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private function assignStoreSelect(int $websiteId, int $storeId = 0): void
    {
        $pack = StoreSelectOptions::forSelect($websiteId, (string)$storeId);
        $this->assign('storeSelectValue', $pack['value']);
        $this->assign('storeSelectDisplay', $pack['display']);
        $this->assign('storeSelectOptionsJson', $pack['options_json']);
    }

    private function handleMutation(string $section, callable $mutation, string $success, string $warehouseTab = ''): string
    {
        $websiteId = 0;
        try {
            $websiteId = $this->postNonNegativeInt('website_id', 0);
            $mutation($websiteId);
            if ($success !== '') {
                $this->getMessageManager()->addSuccess(__($success));
            }
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError(__('操作失败：%{1}', [$exception->getMessage()]));
        }

        $query = 'website_id=' . $websiteId;
        if ($section === 'warehouses' && in_array($warehouseTab, ['tree', 'labels'], true)) {
            $query .= '&wh_tab=' . $warehouseTab;
        }

        return (string)$this->redirect('*/backend/inventory/' . $section . '?' . $query);
    }

    private function postString(string $key, int $maxLength): string
    {
        $value = trim((string)$this->request->getPost($key, ''));
        if ($value === '' || strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(__('%{1} 不能为空且最多 %{2} 字符', [$key, $maxLength]));
        }
        return $value;
    }

    private function postPositiveInt(string $key): int
    {
        $value = $this->postNonNegativeInt($key, 0);
        if ($value <= 0) {
            throw new \InvalidArgumentException(__('%{1} 必须是正整数', [$key]));
        }
        return $value;
    }

    private function postNonNegativeInt(string $key, int $default): int
    {
        $raw = trim((string)$this->request->getPost($key, (string)$default));
        if ($raw === '' || !ctype_digit($raw)) {
            throw new \InvalidArgumentException(__('%{1} 必须是非负整数', [$key]));
        }
        return (int)$raw;
    }
}
